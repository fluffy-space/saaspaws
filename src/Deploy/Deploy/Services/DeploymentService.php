<?php

namespace SaasPaws\Deploy\Deploy\Services;

use SaasPaws\Deploy\Deploy\Data\Entities\DeployApplicationEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployServerEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployServiceEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployServiceEntityMap;
use SaasPaws\Deploy\Deploy\Data\Entities\DeploySourceEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployUpstreamEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployUpstreamEntityMap;
use SaasPaws\Deploy\Deploy\Data\Entities\DeploymentHistoryEntity;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeployApplicationRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeployApplicationServerRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeployServerRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeployServiceRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeploySourceRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeployUpstreamRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeploymentHistoryRepository;
use SaasPaws\Deploy\Ssh\SshCredentials;
use SaasPaws\Deploy\Ssh\SshService;
use Fluffy\Domain\Configuration\Config;
use Throwable;

/**
 * Deploys an application to every server attached to it.
 *
 * The flow is server-admin's, kept deliberately plain — clone, build, replace the release,
 * restart the units — with ONE branch on `DeployApplication.Strategy`:
 *
 *  - `restart` — exactly server-admin: the release directory is replaced in place and the units
 *    are restarted, with the few seconds of downtime that implies.
 *  - `rotate` — blue/green: the release is built into the slot that is NOT serving, started and
 *    health-checked there, and only then do the nginx upstreams move to its ports. The previous
 *    slot is stopped after a drain and stays on disk, which is what makes "roll back" just
 *    another rotation rather than a restore.
 *
 * The two extensions over server-admin live in the rows, not here: an application has SEVERAL
 * units (`DeployService`, each with a slot and a port) and SEVERAL upstreams (`DeployUpstream`,
 * one per location). A rotation repoints each upstream at the port of the service that answers
 * it in the target slot, so an app serving `/` from one unit and `/api` from another moves both
 * together.
 *
 * Commands go through `exec()` rather than an interactive shell: it yields a real exit code, so
 * a failed build cannot let the pipeline reach the swap. Git credentials ride in the clone URL
 * for the same reason — nothing has to answer a prompt that nobody is watching.
 */
class DeploymentService
{
    /** @var callable|null fn(string $chunk): void — live output for a log tail. */
    private $onLog = null;

    private string $logs = '';

    /** Where the current record's own share of `$logs` starts — see finish(). */
    private int $recordLogOffset = 0;

    public function __construct(
        private DeployApplicationRepository $applications,
        private DeploySourceRepository $sources,
        private DeployServerRepository $servers,
        private DeployApplicationServerRepository $links,
        private DeployServiceRepository $services,
        private DeployUpstreamRepository $upstreams,
        private DeploymentHistoryRepository $history,
        private Config $config,
        private ServerInspector $inspector,
        private ServerProvisioner $provisioner,
    ) {
        $this->provisioner->onLog(fn(string $line) => $this->log($line));
    }

    public function onLog(?callable $callback): void
    {
        $this->onLog = $callback;
    }

    public function getLogs(): string
    {
        return $this->logs;
    }

    // ------------------------------------------------------------------ entry points

    /**
     * Deploy an application to all of its servers.
     *
     * @param array $context ['userName' => ?string]
     * @return DeploymentHistoryEntity[] one record per server
     */
    public function deploy(int $applicationId, array $context = []): array
    {
        $this->assertEnabled();
        $app = $this->requireApplication($applicationId);
        $source = $this->requireSource($app);
        $servers = $this->serversFor($applicationId);
        $services = $this->servicesFor($applicationId);
        $upstreams = $this->upstreamsFor($applicationId);

        if (count($services) === 0) {
            throw new DeployException("Application '{$app->Name}' has no services to deploy.", 'preflight');
        }
        // Validated BEFORE a single command runs: a missing slot or an upstream with nothing
        // behind it would otherwise surface mid-rotation, after the build, with traffic about to
        // move. That is the one moment there is nothing useful to do about it.
        if ($this->isRotating($app)) {
            $this->assertRotatable($app, $services, $upstreams);
        }

        $records = [];
        foreach ($servers as $link) {
            $records[] = $this->runOnServer($app, $source, $link->Server, $services, $upstreams, $context);
        }
        return $records;
    }

    /**
     * Write the application's vault file to every server, over SFTP.
     *
     * Its own action, not part of a deploy: production secrets should change when someone means
     * to change them, and a fresh box needs the vault in place BEFORE the first build reads it.
     *
     * @return DeploymentHistoryEntity[] one record per server
     */
    public function deployKeyVault(int $applicationId, array $context = []): array
    {
        $this->assertEnabled();
        $app = $this->requireApplication($applicationId);
        if ($app->KeyVault === null || trim($app->KeyVault) === '') {
            throw new DeployException("Application '{$app->Name}' has no key vault contents to push.", 'vault');
        }
        // Checked HERE, against the stored text, because everything downstream reads this file as
        // JSON: pushing `{}` or a broken paste produces a box that builds and then runs on
        // placeholder secrets. Cheaper to refuse than to discover after a rotation.
        $values = json_decode($app->KeyVault, true);
        if (!is_array($values)) {
            throw new DeployException(
                "The key vault for '{$app->Name}' is not valid JSON (" . json_last_error_msg()
                    . ') — nothing was pushed.',
                'vault'
            );
        }
        if ($values === []) {
            throw new DeployException(
                "The key vault for '{$app->Name}' has no values — pushing it would leave the server with "
                    . 'a placeholder vault and a build that substitutes nothing.',
                'vault'
            );
        }
        // Incomplete is allowed — pushing the vault is how a half-configured box gets fixed one key
        // at a time, and refusing would leave no way to correct it. Only EMPTY is refused (above).
        // The deploy is where this becomes fatal: assertVaultUsable() checks the same keys on the
        // server and stops the deployment there.
        $blank = array_keys(array_filter(
            array_intersect_key($values, array_flip(self::VAULT_REQUIRED_KEYS)) + array_fill_keys(self::VAULT_REQUIRED_KEYS, null),
            fn($value) => $value === null || $value === '' || $value === []
        ));
        $records = [];
        foreach ($this->serversFor($applicationId) as $link) {
            $server = $link->Server;
            $record = $this->startRecord($app, $server, null, 'vault', $context);
            $ssh = null;
            try {
                // Logged inside the record, not before it, so the warning is part of what this push
                // saved rather than only part of the live tail.
                if ($blank !== []) {
                    $this->log('WARNING: the key vault has no value for: ' . implode(', ', $blank)
                        . ' — it is pushed anyway, but a deployment will refuse to build with it.');
                }
                $ssh = $this->connect($app, null, $server);
                $this->writeVault($ssh, $app, $server);
                $record->IsSuccessful = true;
            } catch (Throwable $e) {
                $record->IsSuccessful = false;
                $record->Error = $e->getMessage();
                $this->log('FAILED: ' . $e->getMessage());
                $this->finish($record, $ssh);
                throw $e;
            }
            $this->finish($record, $ssh);
            $records[] = $record;
        }
        return $records;
    }

    /**
     * "Check server": a read-only look at every server of the application (ServerInspector) -
     * prerequisites, what of this application is already there, and what of someone else's is in
     * its way. Nothing on the box changes, so it is safe beside other live applications and can be
     * run any time. A server counts as successful when nothing blocks setting the application up;
     * the findings are in the record's log, one `[status] group: label - detail` line each.
     *
     * @return DeploymentHistoryEntity[]
     */
    public function check(int $applicationId, array $context = []): array
    {
        $this->assertEnabled();
        $app = $this->requireApplication($applicationId);
        /** @var ?DeploySourceEntity $source */
        $source = $app->SourceId ? $this->sources->getById($app->SourceId) : null;
        $services = $this->servicesFor($applicationId);
        $upstreams = $this->upstreamsFor($applicationId);
        $vault = $app->KeyVault !== null && trim($app->KeyVault) !== '' ? json_decode($app->KeyVault, true) : null;
        $vault = is_array($vault) && $vault !== [] ? $vault : null;
        $records = [];
        foreach ($this->serversFor($applicationId) as $link) {
            $server = $link->Server;
            $record = $this->startRecord($app, $server, $source, 'check', $context);
            $ssh = null;
            try {
                $ssh = $this->connect($app, $source, $server);
                $findings = $this->inspector->inspect(
                    $ssh,
                    $app,
                    $source,
                    $server,
                    $services,
                    $upstreams,
                    $vault,
                    $this->baseFolder($app),
                    $this->vaultDir($app) . '/' . $this->vaultFileName($app),
                    fn(string $line) => $this->log($line)
                );
                $counts = array_count_values(array_column($findings, 'status'));
                $summary = sprintf(
                    '%d ok, %d missing, %d conflict, %d error, %d already there, %d warning',
                    $counts[ServerInspector::OK] ?? 0,
                    $counts[ServerInspector::MISSING] ?? 0,
                    $counts[ServerInspector::CONFLICT] ?? 0,
                    $counts[ServerInspector::ERROR] ?? 0,
                    $counts[ServerInspector::EXISTS] ?? 0,
                    $counts[ServerInspector::WARN] ?? 0
                );
                $ready = ServerInspector::ready($findings);
                $this->log(($ready ? 'READY to set up: ' : 'NOT READY: ') . $summary);
                $record->IsSuccessful = $ready;
                $record->Error = $ready ? null : $summary;
            } catch (Throwable $e) {
                $record->IsSuccessful = false;
                $record->Error = $e->getMessage();
                $this->log('FAILED: ' . $e->getMessage());
            }
            $this->finish($record, $ssh);
            $records[] = $record;
        }
        return $records;
    }

    /**
     * "Set up": everything a fresh box needs before the ordinary Deploy can take over, done as
     * the first deploy. Per server, in order, each step safe to run again after a failure:
     *
     *  1. Check (ServerInspector): refuse when anything is missing, in the way or unknown.
     *  2. Workspace (prod/10-workspace.sh): folders, owner, the deploy login in the owner's group.
     *     Reconnect, so that membership applies.
     *  3. Key vault, as Push vault does.
     *  4. PostgreSQL: role, database, `_verify` database, citext.
     *  5. First release into the first slot: clone, build, claim the Redis db index, migrate.
     *  6. Units (`php fluffy service`) after checking the release names the deploy admin's units,
     *     upstream and user; start the slot, wait until healthy, the verify suite if asked for.
     *  7. Web server (`php fluffy nginx`), tested and rolled back if it breaks the config, then
     *     proven to route to the app through 127.0.0.1.
     *  8. Enable the slot at boot, write the live colour (last, so a failed run can run again),
     *     record the live slot on the application.
     *
     * Refuses on a box where the application is already set up (it has a live colour): from then
     * on it is Deploy's job. The certificate is NOT issued here (Let's Encrypt limits failed
     * attempts); the vhost starts on the self-signed pair.
     *
     * @return DeploymentHistoryEntity[]
     */
    public function setup(int $applicationId, array $context = []): array
    {
        $this->assertEnabled();
        $app = $this->requireApplication($applicationId);
        if (!$this->isRotating($app)) {
            throw new DeployException('Set up is built for the rotate (blue/green) strategy.', 'preflight');
        }
        $source = $this->requireSource($app);
        $services = $this->servicesFor($applicationId);
        $upstreams = $this->upstreamsFor($applicationId);
        $this->assertRotatable($app, $services, $upstreams);
        if (count($upstreams) !== 1) {
            throw new DeployException("Set up handles one upstream (`php fluffy nginx` writes one); '{$app->Name}' has " . count($upstreams) . '.', 'preflight');
        }
        $builderConf = '/etc/nginx/conf.d/10-upstream-' . $upstreams[0]->Name . '.conf';
        if ($upstreams[0]->confPath() !== $builderConf) {
            throw new DeployException("The upstream's file is set to {$upstreams[0]->confPath()}, but `php fluffy nginx` writes $builderConf: clear the custom path or set the box up by hand.", 'preflight');
        }
        $domain = strtolower(trim((string) $app->Domain));
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            throw new DeployException("The application's domain '{$app->Domain}' is not a host name.", 'preflight');
        }
        $slots = $this->slots($services);
        $vault = json_decode((string) $app->KeyVault, true);
        if (!is_array($vault) || $vault === []) {
            throw new DeployException("The application's key vault is empty or not JSON.", 'preflight');
        }
        $records = [];
        foreach ($this->serversFor($applicationId) as $link) {
            $server = $link->Server;
            $record = $this->startRecord($app, $server, $source, 'setup', $context);
            $ssh = null;
            $locked = null;
            try {
                $ssh = $this->connect($app, $source, $server);
                $base = $this->baseFolder($app);
                $to = $slots[0];
                $record->ToSlot = $to;

                $this->log('== step 1/8: check');
                $findings = $this->inspector->inspect(
                    $ssh, $app, $source, $server, $services, $upstreams, $vault,
                    $base, $this->vaultDir($app) . '/' . $this->vaultFileName($app),
                    fn(string $line) => $this->log($line)
                );
                $blockers = ServerInspector::blockers($findings);
                if ($blockers !== []) {
                    throw new DeployException('Not ready: ' . implode('; ', array_map(fn($f) => "{$f['group']}: {$f['label']} ({$f['detail']})", $blockers)), 'preflight');
                }
                // The live colour is written only at the very end, so its presence means a finished
                // set up: from then on it is Deploy's job. A run that failed half way can run again.
                if ($ssh->test('test -f ' . escapeshellarg($base . '/live-color'))) {
                    throw new DeployException('The application is already set up on this box (it has a live colour): use Deploy.', 'preflight');
                }
                // One Set up per application and box at a time (two tabs, a double confirm).
                $lock = '/run/lock/saaspaws-setup-' . ServerProvisioner::slug((string) $app->Name);
                if (!$ssh->sudo('mkdir ' . escapeshellarg($lock), false, 15)->ok()) {
                    throw new DeployException("Another Set up of {$app->Name} is running on this box ($lock exists; remove it if none is).", 'preflight');
                }
                $locked = $lock;
                // Again under the lock: a run that finished while this one was checking set it up.
                if ($ssh->test('test -f ' . escapeshellarg($base . '/live-color'))) {
                    throw new DeployException('The application was set up on this box meanwhile (it has a live colour): use Deploy.', 'preflight');
                }

                $this->log('== step 2/8: workspace');
                $login = (string) $server->UserName;
                $this->provisioner->workspace($ssh, $base, self::APP_USER, $login !== 'root' ? $login : null);
                $ssh->dispose();
                $ssh = $this->connect($app, $source, $server); // the new group applies to new logins only
                $rotator = new BlueGreenRotator($ssh);

                $this->log('== step 3/8: key vault');
                $this->writeVault($ssh, $app, $server);

                $this->log('== step 4/8: PostgreSQL');
                $this->provisioner->postgres($ssh, $vault, (string) $app->Name);

                $this->log("== step 5/8: first release into '$to'");
                $release = $this->slotFolder($app, $to);
                $staging = $release . '_staging';
                $this->fetch($record, $app, $source, $ssh, $staging);
                $this->build($app, $source, $ssh, $staging);
                $this->swap($ssh, $staging, $release);
                $this->provisioner->assertSnippet($ssh, $release);
                $this->provisioner->assertFallbacks($ssh, $release, $domain);
                $this->provisioner->redis($ssh, $release, (string) $app->Name);
                $this->migrate($ssh, $release);

                $this->log("== step 6/8: units, start '$to'");
                $this->assertReleaseMatches($ssh, $release, $services, $upstreams);
                $unitNames = array_map(fn($service) => preg_replace('/\.service$/', '', (string) $service->Name), $services);
                $this->provisioner->units($ssh, $release, $base, $unitNames);
                foreach ($this->slotServices($services, $to) as $service) {
                    $rotator->restartUnit($service->Name);
                }
                $port = 0;
                foreach ($this->slotServices($services, $to) as $service) {
                    if ($service->UpstreamId !== null) {
                        $rotator->waitHealthy($service->Name, $service->Port, $app->HealthPath ?: '/health', 90);
                        $this->log("{$service->Name} is healthy on port {$service->Port}");
                        $port = (int) $service->Port;
                    }
                }
                $this->verifySlot($app, $ssh, $release, $this->slotServices($services, $to));

                $this->log('== step 7/8: web server');
                $this->provisioner->edge($ssh, $release, $base, $domain, array_map(fn($u) => $u->confPath(), $upstreams), $port);
                $serving = $this->liveSlot($rotator, $services, $upstreams);
                if ($serving !== $to) {
                    throw new DeployException("The upstream points at '" . ($serving ?? 'nothing') . "', not at '$to' which was just started.", 'edge');
                }

                $this->log('== step 8/8: live');
                $this->provisioner->enable($ssh, array_map(fn($service) => (string) $service->Name, $this->slotServices($services, $to)));
                $rotator->writeLiveMarker($base, $to);
                $app->LiveSlot = $to;
                $app->LiveCommitSha = $record->CommitSha;
                $app->LastDeployedOn = DeploymentHistoryRepository::getTime();
                $this->applications->update($app, ['LiveSlot', 'LiveCommitSha', 'LastDeployedOn']);
                $this->log("Set up on {$server->Name}: '$to' serves https://{$app->Domain} on the self-signed certificate. Next: Issue certificate.");
                $record->IsSuccessful = true;
            } catch (Throwable $e) {
                $record->IsSuccessful = false;
                $record->Error = $e->getMessage();
                $this->log('FAILED: ' . $e->getMessage());
                $this->unlock($ssh, $locked);
                $this->finish($record, $ssh);
                throw $e;
            }
            $this->unlock($ssh, $locked);
            $this->finish($record, $ssh);
            $records[] = $record;
        }
        return $records;
    }

    private function unlock(?SshService $ssh, ?string $lock): void
    {
        if ($ssh !== null && $lock !== null) {
            try {
                $ssh->sudo('rmdir ' . escapeshellarg($lock), false, 15);
            } catch (Throwable) {
                // the connection is gone; the next run says how to clear it
            }
        }
    }

    /**
     * "Issue certificate": a Let's Encrypt certificate for the application's domain on every server,
     * and the vhost switched onto it (ServerProvisioner::certificate). Only where Set up finished
     * (a live colour): it regenerates the vhost from the LIVE release.
     *
     * @return DeploymentHistoryEntity[]
     */
    public function certificate(int $applicationId, array $context = []): array
    {
        $this->assertEnabled();
        $app = $this->requireApplication($applicationId);
        $services = $this->servicesFor($applicationId);
        $upstreams = $this->upstreamsFor($applicationId);
        $domain = strtolower(trim((string) $app->Domain));
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            throw new DeployException("The application's domain '{$app->Domain}' is not a host name.", 'preflight');
        }
        $vault = json_decode((string) $app->KeyVault, true);
        $email = is_array($vault) ? (string) ($vault['ADMINS_ARRAY'][0]['Email'] ?? '') : '';
        $records = [];
        foreach ($this->serversFor($applicationId) as $link) {
            $server = $link->Server;
            $record = $this->startRecord($app, $server, null, 'certificate', $context);
            $ssh = null;
            try {
                $ssh = $this->connect($app, null, $server);
                $base = $this->baseFolder($app);
                $slot = trim($ssh->exec('cat ' . escapeshellarg($base . '/live-color'), false, 15)->output);
                if ($slot === '' || !in_array($slot, $this->slots($services), true)) {
                    throw new DeployException('No live colour on this box: run Set up first.', 'preflight');
                }
                $release = $this->slotFolder($app, $slot);
                $port = 0;
                foreach ($this->slotServices($services, $slot) as $service) {
                    if ($service->UpstreamId !== null) {
                        $port = (int) $service->Port;
                    }
                }
                $this->provisioner->assertSnippet($ssh, $release);
                $this->provisioner->certificate($ssh, $release, $base, $domain, array_map(fn($u) => $u->confPath(), $upstreams), $port, $email);
                $record->IsSuccessful = true;
            } catch (Throwable $e) {
                $record->IsSuccessful = false;
                $record->Error = $e->getMessage();
                $this->log('FAILED: ' . $e->getMessage());
                $this->finish($record, $ssh);
                throw $e;
            }
            $this->finish($record, $ssh);
            $records[] = $record;
        }
        return $records;
    }

    /**
     * The release's configs/server.php names the units, their ports and the upstream that Fluffy's
     * builders will write as root; they must be the deploy admin's, or a builder would overwrite
     * another application's unit or point our vhost at someone else's upstream.
     *
     * @param DeployServiceEntity[] $services
     * @param DeployUpstreamEntity[] $upstreams
     */
    private function assertReleaseMatches(SshService $ssh, string $release, array $services, array $upstreams): void
    {
        $config = $this->provisioner->releaseConfig($ssh, $release);
        $problems = [];
        $extra = array_diff(array_keys($config['instances']), $this->slots($services));
        if ($extra !== []) {
            // `php fluffy service` writes a unit for EVERY instance, as root.
            $problems[] = 'configs/server.php has instances the deploy admin does not: ' . implode(', ', $extra);
        }
        foreach ($services as $service) {
            $slot = $this->slotOf($service);
            if ($slot === null) {
                continue;
            }
            $instance = $config['instances'][$slot] ?? null;
            $name = preg_replace('/\.service$/', '', (string) $service->Name);
            if ($instance === null) {
                $problems[] = "no '$slot' instance in configs/server.php";
            } elseif (preg_replace('/\.service$/', '', $instance['service']) !== $name || $instance['port'] !== (int) $service->Port) {
                $problems[] = "'$slot' is {$instance['service']}:{$instance['port']} in configs/server.php but $name:{$service->Port} here";
            }
        }
        if ($config['user'] !== self::APP_USER) {
            $problems[] = "the units would run as '" . ($config['user'] ?? 'nobody named') . "' (service_user), not " . self::APP_USER;
        }
        $upstream = $upstreams[0]->Name ?? null;
        if ($config['upstream'] !== $upstream) {
            $problems[] = "the upstream is '" . ($config['upstream'] ?? 'none') . "' in configs/server.php but '$upstream' here";
        }
        if ($problems !== []) {
            throw new DeployException('The release and the deploy admin disagree: ' . implode('; ', $problems) . '.', 'units');
        }
    }

    /**
     * Writes the application's key vault to one server (Push vault, and step 3 of Set up): beside
     * the vault, then renamed over it, checked byte for byte; the reasons are with each step below.
     */
    private function writeVault(SshService $ssh, DeployApplicationEntity $app, DeployServerEntity $server): void
    {
        $dir = $this->vaultDir($app);
        $path = $dir . '/' . $this->vaultFileName($app);
        $this->log("Writing the key vault to {$server->Name}:$path");
        $ssh->sudo('mkdir -p ' . escapeshellarg($dir));
        if (!$ssh->test('test -w ' . escapeshellarg($dir))) {
            throw new DeployException(
                "$dir is not writable by the deploy user on {$server->Name}. Re-run "
                    . "prod/10-workspace.sh with this login as the deployer argument, and "
                    . "reconnect so the new group membership applies.",
                'vault'
            );
        }
        // WRITTEN BESIDE THE VAULT AND RENAMED OVER IT, never deleted first. The installer
        // writes a placeholder vault (prod/install.sh step 4) so the box can boot before
        // the real secrets arrive; removing it to make room means a transfer that then
        // fails leaves the box with NO vault at all, and the next build dies on a missing
        // file instead of on the push that actually broke. A rename is atomic, so the path
        // holds either the old file or the new one and never a half-written one.
        //
        // 0600 on the temporary file: it holds every production credential and must not be
        // readable by anyone else even for the moment it sits beside the real one. The
        // rename carries that mode across, and the vault is a build-time input read by
        // this same login (`getVaultKeys` in configs/build.php): the runtime never reads
        // it, only the generated configs/app.php inside the release.
        //
        // writeFile() counts the bytes on the far side and throws if they do not match, so
        // a truncated transfer cannot reach the rename below. It used to be able to: the
        // old SCP transport wrote a 0-byte file and reported success (see writeFile()).
        $incoming = $path . '.incoming';
        try {
            $ssh->writeFile($incoming, $app->KeyVault, 0600);
        } catch (Throwable $e) {
            // The redirection creates the destination before the transfer can fail, so a
            // refused or truncated write leaves a zero-length or partial file behind.
            // Harmless where it is, but it is still a secrets file, so do not leave it in the
            // vault directory.
            $ssh->sudo('rm -f ' . escapeshellarg($incoming), enforce: false);
            throw $e;
        }
        $ssh->sudo('mv -f ' . escapeshellarg($incoming) . ' ' . escapeshellarg($path));

        // Group-readable, group inherited from the vault directory (www-data, per
        // prod/10-workspace.sh). NOT cosmetic: the file lands owned by this login, and a
        // later `chown -R www-data` (which prod/install.sh does to the whole workspace on
        // every run) turns a 0600 file into one this login can no longer read. The build
        // then reports the vault as missing, the vault is pushed again, and the next
        // install.sh run breaks it again. At 0660 with the app's group, either owner can
        // read it whichever way the last chown went, and "other" still gets nothing.
        $ssh->sudo('chgrp --reference=' . escapeshellarg($dir) . ' ' . escapeshellarg($path), enforce: false);
        $normalized = $ssh->sudo('chmod 660 ' . escapeshellarg($path), enforce: false);
        if (!$normalized->ok()) {
            $this->log("WARNING: could not set 0660 on $path: if it is not readable by both the "
                . 'deploy login and the app user, a later chown will lock one of them out.');
        }

        // Verify the FINAL path, after the rename and the mode changes: writeFile() already
        // counted the bytes at the incoming path, and this is the file the build will open.
        // A short file here is not an error anywhere: it is a file, and every later step
        // reads it as "the vault".
        $written = (int) trim($ssh->read('wc -c < ' . escapeshellarg($path)));
        $expectedBytes = strlen($app->KeyVault);
        if ($written !== $expectedBytes) {
            throw new DeployException(
                "The key vault on {$server->Name} is $written bytes but should be $expectedBytes: "
                    . 'the transfer was truncated. The file at ' . $path . ' is NOT usable; push again.',
                'vault'
            );
        }
        $this->log("Wrote $written bytes to {$server->Name}:$path");
    }

    // ------------------------------------------------------------------ one server

    /**
     * @param DeployServiceEntity[] $services
     * @param DeployUpstreamEntity[] $upstreams
     */
    private function runOnServer(
        DeployApplicationEntity $app,
        DeploySourceEntity $source,
        DeployServerEntity $server,
        array $services,
        array $upstreams,
        array $context
    ): DeploymentHistoryEntity {
        $record = $this->startRecord($app, $server, $source, 'deploy', $context);
        $ssh = null;
        try {
            $ssh = $this->connect($app, $source, $server);
            if ($this->isRotating($app)) {
                $this->rotateDeploy($record, $app, $source, $ssh, $services, $upstreams);
            } else {
                $this->restartDeploy($record, $app, $source, $ssh, $services);
            }
            $record->IsSuccessful = true;
        } catch (Throwable $e) {
            $record->IsSuccessful = false;
            $record->Error = $e->getMessage();
            $this->log('FAILED: ' . $e->getMessage());
            $this->finish($record, $ssh);
            throw $e;
        }
        $this->finish($record, $ssh);
        return $record;
    }

    /**
     * server-admin's original: build beside the release, replace it, restart everything.
     *
     * @param DeployServiceEntity[] $services
     */
    private function restartDeploy(
        DeploymentHistoryEntity $record,
        DeployApplicationEntity $app,
        DeploySourceEntity $source,
        SshService $ssh,
        array $services
    ): void {
        $release = $this->appFolder($app);
        $staging = $release . '_staging';
        $this->log("Deploying {$app->Name} into $release (restart strategy — expect a short outage)");

        $this->fetch($record, $app, $source, $ssh, $staging);
        $this->build($app, $source, $ssh, $staging);
        $this->swap($ssh, $staging, $release);
        $this->migrate($ssh, $release);

        $rotator = new BlueGreenRotator($ssh);
        foreach ($services as $service) {
            $this->log("Restarting {$service->Name}");
            $rotator->restartUnit($service->Name);
        }

        $app->LiveCommitSha = $record->CommitSha;
        $app->LastDeployedOn = DeploymentHistoryRepository::getTime();
        $this->applications->update($app, ['LiveCommitSha', 'LastDeployedOn']);
    }

    /**
     * Blue/green: build into the idle slot, prove it healthy, then move the upstreams.
     *
     * Nothing on the live path is touched until the build has succeeded and the new units answer
     * on the loopback, so a broken commit fails with the previous slot still serving.
     *
     * @param DeployServiceEntity[] $services
     * @param DeployUpstreamEntity[] $upstreams
     */
    private function rotateDeploy(
        DeploymentHistoryEntity $record,
        DeployApplicationEntity $app,
        DeploySourceEntity $source,
        SshService $ssh,
        array $services,
        array $upstreams
    ): void {
        $rotator = new BlueGreenRotator($ssh);
        $slots = $this->slots($services);
        $from = $this->liveSlot($rotator, $services, $upstreams);
        $to = $from === null ? $slots[0] : $this->otherSlot($slots, $from);
        $record->FromSlot = $from;
        $record->ToSlot = $to;
        $this->log($from === null
            ? "Deploying {$app->Name} into '$to' (nothing is live yet on this box)"
            : "Deploying {$app->Name}: '$from' -> '$to'");

        $release = $this->slotFolder($app, $to);
        $staging = $release . '_staging';

        $this->fetch($record, $app, $source, $ssh, $staging);
        $this->build($app, $source, $ssh, $staging);
        $this->swap($ssh, $staging, $release);
        // From the NEW release, before it takes traffic — so a migration has to stay compatible
        // with the slot that is still serving. That constraint is inherent to zero-downtime, not
        // something this tool can check.
        $this->migrate($ssh, $release);

        $this->rotateOnto($app, $ssh, $rotator, $services, $upstreams, $from, $to, $release);

        $app->LiveSlot = $to;
        $app->LiveCommitSha = $record->CommitSha;
        $app->LastDeployedOn = DeploymentHistoryRepository::getTime();
        $this->applications->update($app, ['LiveSlot', 'LiveCommitSha', 'LastDeployedOn']);
    }

    /**
     * Restart onto the SAME release — for after the box itself changed (a PHP or Swoole rebuild,
     * an OS update) and the running units still hold the old binaries in memory.
     *
     * Nothing is cloned, built or migrated. Under `rotate` the live release is copied into the
     * idle slot and rotated onto exactly like a deploy, so there is no downtime; the idle slot
     * cannot simply be started as it is, because it holds the PREVIOUS release and rotating onto
     * it would be a rollback. A release copies safely: it resolves its port from its directory
     * name (configs/server.php) and every path it uses is relative to itself.
     *
     * Under `restart` the units are restarted in place, downtime included — as a deploy would.
     *
     * @param array $context ['userName' => ?string]
     * @return DeploymentHistoryEntity[] one record per server
     */
    public function restart(int $applicationId, array $context = []): array
    {
        $this->assertEnabled();
        $app = $this->requireApplication($applicationId);
        $servers = $this->serversFor($applicationId);
        $services = $this->servicesFor($applicationId);
        $upstreams = $this->upstreamsFor($applicationId);

        if (count($services) === 0) {
            throw new DeployException("Application '{$app->Name}' has no services to restart.", 'preflight');
        }
        if ($this->isRotating($app)) {
            $this->assertRotatable($app, $services, $upstreams);
        }

        $records = [];
        foreach ($servers as $link) {
            $server = $link->Server;
            $record = $this->startRecord($app, $server, null, 'restart', $context);
            $ssh = null;
            try {
                $ssh = $this->connect($app, null, $server);
                $rotator = new BlueGreenRotator($ssh);
                if ($this->isRotating($app)) {
                    $this->rotateRestart($record, $app, $server, $ssh, $rotator, $services, $upstreams);
                } else {
                    $this->log("Restarting {$app->Name} in place (restart strategy — expect a short outage)");
                    foreach ($services as $service) {
                        $this->log("Restarting {$service->Name}");
                        $rotator->restartUnit($service->Name);
                    }
                    foreach ($services as $service) {
                        if ($service->UpstreamId !== null && $service->Port > 0) {
                            $rotator->waitHealthy($service->Name, $service->Port, $app->HealthPath ?: '/health', 90);
                            $this->log("{$service->Name} is healthy on port {$service->Port}");
                        }
                    }
                    $record->CommitSha = $app->LiveCommitSha;
                }
                $record->IsSuccessful = true;
            } catch (Throwable $e) {
                $record->IsSuccessful = false;
                $record->Error = $e->getMessage();
                $this->log('FAILED: ' . $e->getMessage());
                $this->finish($record, $ssh);
                throw $e;
            }
            $this->finish($record, $ssh);
            $records[] = $record;
        }
        return $records;
    }

    /**
     * @param DeployServiceEntity[] $services
     * @param DeployUpstreamEntity[] $upstreams
     */
    private function rotateRestart(
        DeploymentHistoryEntity $record,
        DeployApplicationEntity $app,
        DeployServerEntity $server,
        SshService $ssh,
        BlueGreenRotator $rotator,
        array $services,
        array $upstreams
    ): void {
        $from = $this->liveSlot($rotator, $services, $upstreams);
        if ($from === null) {
            throw new DeployException(
                "Nothing recognisable is live on {$server->Name} — there is no release to restart. Deploy instead.",
                'preflight'
            );
        }
        $to = $this->otherSlot($this->slots($services), $from);
        $record->FromSlot = $from;
        $record->ToSlot = $to;

        $live = $this->slotFolder($app, $from);
        $release = $this->slotFolder($app, $to);
        $staging = $release . '_staging';
        $this->log("Restarting {$app->Name} on the same release: copying '$from' into '$to', then rotating");

        // What is being restarted, for the history row. safe.directory because the release is
        // www-data's and git refuses to read a repository owned by someone else.
        $head = $ssh->exec(
            'git -c safe.directory=' . escapeshellarg($live) . ' -C ' . escapeshellarg($live)
                . ' log -1 --pretty=%H%n%s',
            enforce: false
        );
        $lines = explode("\n", trim($head->text()), 2);
        $record->CommitSha = $head->ok() && $lines[0] !== '' ? $lines[0] : $app->LiveCommitSha;
        $record->CommitMessage = $head->ok() ? substr($lines[1] ?? '', 0, 400) : null;
        $this->log("Commit {$record->CommitSha} — {$record->CommitMessage}");

        // Copied beside the target and swapped in, as a build would be: the idle release is only
        // removed once a complete copy exists. -a keeps www-data's ownership and the modes.
        $ssh->sudo('rm -rf ' . escapeshellarg($staging));
        $ssh->sudo('cp -a ' . escapeshellarg($live) . ' ' . escapeshellarg($staging), timeout: 600);
        // The live slot's verify run left its session cache behind (gitignored, so a fresh clone
        // has none). Once swap() hands the copy to www-data the deploy login can neither read nor
        // rewrite it, and the suite warns on every request.
        $ssh->sudo('rm -f ' . escapeshellarg($staging) . '/scripts/verify/.session-*.json');
        // The idle slot holds the PREVIOUS release, the one a roll back rotates onto. A restart
        // would otherwise delete it (both slots end up on the same code, and a bad release has
        // nothing left to go back to), so it is parked beside the slot instead, one deep, and
        // moving it back is a rename. Not moved back automatically: after the rotation the old
        // slot is still draining from its folder, and after a failed check the new slot's units
        // are running from theirs, so neither folder can be replaced from here.
        $parked = $release . '_previous';
        $ssh->sudo('rm -rf ' . escapeshellarg($parked));
        if ($ssh->sudo('mv ' . escapeshellarg($release) . ' ' . escapeshellarg($parked), enforce: false)->ok()) {
            $this->log("The previous release in '$to' is kept at $parked (rename it back to roll back)");
        }
        $this->swap($ssh, $staging, $release);

        $this->rotateOnto($app, $ssh, $rotator, $services, $upstreams, $from, $to, $release);

        // LiveCommitSha is unchanged — the same code is serving — and LastDeployedOn stays the
        // date the code last changed.
        $app->LiveSlot = $to;
        $this->applications->update($app, ['LiveSlot']);
    }

    /**
     * The second half of a rotation, shared by deploy and restart: start the target slot, prove
     * it, move the upstreams, check the edge (rotating back on failure), restart the slotless
     * services and drain the old slot.
     *
     * @param DeployServiceEntity[] $services
     * @param DeployUpstreamEntity[] $upstreams
     */
    private function rotateOnto(
        DeployApplicationEntity $app,
        SshService $ssh,
        BlueGreenRotator $rotator,
        array $services,
        array $upstreams,
        ?string $from,
        string $to,
        string $release
    ): void {
        // Start the target slot and prove it answers, still outside every upstream.
        foreach ($this->slotServices($services, $to) as $service) {
            $this->log("Starting {$service->Name}");
            $rotator->restartUnit($service->Name);
        }
        foreach ($this->slotServices($services, $to) as $service) {
            if ($service->UpstreamId === null) {
                continue; // a worker has no port to poll
            }
            $rotator->waitHealthy($service->Name, $service->Port, $app->HealthPath ?: '/health', 90);
            $this->log("{$service->Name} is healthy on port {$service->Port}");
        }

        // The slot answers and is healthy, but healthy only means the process is up. Before any
        // traffic moves, make it prove the app actually works.
        $this->verifySlot($app, $ssh, $release, $this->slotServices($services, $to));

        // Move every upstream to the port that answers it in the new slot — together.
        $moves = [];
        foreach ($upstreams as $upstream) {
            $service = $this->serviceFor($services, $upstream->Id, $to);
            if ($service === null) {
                continue; // asserted at preflight; a non-rotating upstream simply stays put
            }
            $moves[] = [
                'conf' => $upstream->confPath(),
                'port' => $service->Port,
                'label' => $upstream->Name,
            ];
        }
        $rotator->repoint($moves);
        $rotator->writeLiveMarker($this->baseFolder($app), $to);
        $this->log("Traffic is now on '$to'");

        try {
            $rotator->verifyThroughEdge($app->Domain, $app->HealthPath ?: '/health', 30);
        } catch (DeployException $e) {
            // The new slot answered on the loopback but the site does not answer publicly.
            // Going back is strictly better than leaving it broken while we work out why.
            if ($from !== null) {
                $this->log('Edge check failed — putting traffic back on ' . $from);
                $this->rotateBack($rotator, $app, $services, $upstreams, $from);
            }
            throw $e;
        }

        // The site answers through the edge: from now on only this slot starts at boot. Not
        // earlier, or a rotation that was rolled back would leave the broken slot as the one a
        // reboot brings up.
        foreach ($this->slotServices($services, $to) as $service) {
            $ssh->sudo('systemctl enable ' . escapeshellarg((string) $service->Name), false, 60);
        }
        if ($from !== null && $from !== $to) {
            foreach ($this->slotServices($services, $from) as $service) {
                $ssh->sudo('systemctl disable ' . escapeshellarg((string) $service->Name), false, 60);
            }
        }

        // Services with no slot (a worker, a queue consumer) run from wherever they were
        // installed and simply want the new code; they are restarted once traffic has moved so a
        // failed rotation does not disturb them.
        foreach ($services as $service) {
            if ($this->slotOf($service) !== null) {
                continue;
            }
            $this->log("Restarting {$service->Name}");
            $rotator->restartUnit($service->Name);
        }

        if ($from !== null && $from !== $to) {
            // The old slot stopped taking cron work the moment the marker flipped, so this window
            // only has to outlast what was already in flight. Swoole then gives its workers
            // OPTION_MAX_WAIT_TIME on top before killing them.
            if ($app->DrainSeconds < 10) {
                $this->log(
                    "WARNING: the drain is only {$app->DrainSeconds}s — in-flight requests on '$from' "
                        . 'may be cut short.'
                );
            }
            foreach ($this->slotServices($services, $from) as $service) {
                $rotator->stopDetached($service->Name, $app->DrainSeconds);
            }
            $this->log("'$from' stops in {$app->DrainSeconds}s (detached; its release stays on disk)");
        }
    }

    /**
     * Run the verification suite against the slot that is about to take traffic — and refuse to
     * rotate onto it if the suite fails. The suite is the app's own scripts/verify/ (for example a
     * sweep of every page, its canonical and its script bundle).
     *
     * WHY IT RUNS ON THE BOX. The new slot answers on the loopback only: its port is not in any
     * upstream yet, which is the whole point of rotating. So the suite runs from the release that
     * was just built — a clone of the repo, so it carries the version of the suite that matches the
     * code being deployed — against `http://127.0.0.1:<port>`.
     *
     * SERVER SUITES ONLY, and only the ProdSafe ones: there is no browser on a production box by
     * design, and a suite that mutates data has no business running against production. `--no-record`
     * because a deploy has no business writing test history into the live database either.
     *
     * A non-zero exit aborts the deploy BEFORE `repoint()`, so the slot that is already serving
     * carries on serving and the failure costs a release directory rather than an outage.
     */
    private function verifySlot(
        DeployApplicationEntity $app,
        SshService $ssh,
        string $release,
        array $slotServices
    ): void {
        if (!$app->VerifyBeforeRotate) {
            return;
        }

        // The port the suite should hit: the first service in the target slot that serves an
        // upstream. A worker has no port and nothing to verify.
        $port = 0;
        foreach ($slotServices as $service) {
            if ($service->UpstreamId !== null) {
                $port = $service->Port;
                break;
            }
        }
        if ($port === 0) {
            $this->log('Verification skipped: no port-serving service in the target slot.');
            return;
        }

        $script = $release . '/scripts/verify/run-all.sh';
        // -f, not -x: the suite is invoked through `bash`, so its mode does not matter, and a
        // release whose checkout lost the executable bit must not fail a deploy for that reason.
        if (!$ssh->test('test -f ' . escapeshellarg($script))) {
            throw new DeployException(
                "VerifyBeforeRotate is on, but $script is not in the release. Either the build "
                    . 'strips it or the flag is set on an application that has no suite.',
                'verify'
            );
        }

        $this->log("Verifying the new slot on port $port before moving traffic");
        $command = 'cd ' . escapeshellarg($release) . ' && bash ' . escapeshellarg($script)
            . ' server --smoke --no-record --base=http://127.0.0.1:' . $port;
        // enforce: false — a failing suite is a normal outcome here and its OUTPUT is the useful
        // part, so the exit code is read rather than thrown on.
        // NOT logged again afterwards: exec() already streams the suite's output into this log as it
        // arrives, so echoing $result->text() here printed all 362 lines a second time.
        $result = $ssh->exec($command, enforce: false, timeout: 600);

        if ($result->exitCode !== 0) {
            throw new DeployException(
                "The new slot failed verification ({$result->exitCode} failing checks): traffic was NOT moved; "
                    . "whatever served '{$app->Name}' before (nothing, on a fresh Set up) still does.",
                'verify'
            );
        }
        $this->log('Verification passed — the new slot is serving correctly on the loopback');
    }

    /** Put the upstreams back on a slot that is still installed, starting it if it was stopped. */
    private function rotateBack(
        BlueGreenRotator $rotator,
        DeployApplicationEntity $app,
        array $services,
        array $upstreams,
        string $slot
    ): void {
        foreach ($this->slotServices($services, $slot) as $service) {
            if (!$rotator->isRunning($service->Name)) {
                $rotator->startUnit($service->Name);
            }
            if ($service->UpstreamId !== null) {
                $rotator->waitHealthy($service->Name, $service->Port, $app->HealthPath ?: '/health', 60);
            }
        }
        $moves = [];
        foreach ($upstreams as $upstream) {
            $service = $this->serviceFor($services, $upstream->Id, $slot);
            if ($service !== null) {
                $moves[] = ['conf' => $upstream->confPath(), 'port' => $service->Port, 'label' => $upstream->Name];
            }
        }
        $rotator->repoint($moves);
        $rotator->writeLiveMarker($this->baseFolder($app), $slot);
    }

    // ------------------------------------------------------------------ steps

    /** Clone the branch into a staging directory beside the release and record what landed. */
    private function fetch(
        DeploymentHistoryEntity $record,
        DeployApplicationEntity $app,
        DeploySourceEntity $source,
        SshService $ssh,
        string $staging
    ): void {
        $ssh->sudo('mkdir -p ' . escapeshellarg($this->baseFolder($app)));
        // The clone and the build run as the login user, so the staging directory is handed to
        // it — and only it. Nothing else changes hands: the release itself is www-data's, and a
        // deploy that chowned the whole tree to a human would be a permission bug waiting to
        // happen. `git clone` is happy to populate an existing empty directory.
        $ssh->sudo('rm -rf ' . escapeshellarg($staging));
        $this->takeOwnership($ssh, $staging);
        $ssh->exec(
            'git clone --depth 50 --single-branch --branch ' . escapeshellarg($source->GitBranch ?: 'main') . ' '
                . escapeshellarg($this->repoUrl($source)) . ' ' . escapeshellarg($staging),
            timeout: 600
        );
        // The clone keeps its URL in .git/config, token included, readable by the app's user once
        // the release is chowned to it. Nothing later needs it: put the plain URL back.
        if (!$ssh->exec('cd ' . escapeshellarg($staging) . ' && git remote set-url origin ' . escapeshellarg((string) $source->GitRepository), false)->ok()) {
            $this->log("WARNING: could not take the token out of $staging/.git/config: remove it by hand.");
        }
        $record->CommitSha = $ssh->read('cd ' . escapeshellarg($staging) . ' && git rev-parse HEAD');
        $record->CommitMessage = substr(
            $ssh->read('cd ' . escapeshellarg($staging) . ' && git log -1 --pretty=%s'),
            0,
            400
        );
        $this->log("Commit {$record->CommitSha} — {$record->CommitMessage}");
    }

    /**
     * Everything here happens in staging — with ONE exception that has to be handled.
     *
     * `php fluffy build` copies the compiled assets to `<staging>/../public` (configs/build.php),
     * a directory SHARED by both slots and owned by www-data (prod/10-workspace.sh). It is not
     * part of the staging tree, the build runs as the login user, and www-data 755 means that
     * user can traverse it but not write — so the copy fails on the first `mkdir`.
     *
     * It is therefore handed over for the build and handed straight back: a web-served directory
     * left owned by the deploy account is not a state to keep between deploys.
     */
    private function build(
        DeployApplicationEntity $app,
        DeploySourceEntity $source,
        SshService $ssh,
        string $staging
    ): void {
        // Derived from staging rather than from the application row, because this is exactly what
        // configs/build.php computes — `getBaseDir() . '/../public'` — under either strategy.
        $base = rtrim(dirname($staging), '/');
        $public = $base . '/public';
        $vaultDir = $base . '/vault';

        // Handed over, not checked and refused. prod/10-workspace.sh can put the deploy user in
        // the www-data group instead, which is tidier — but a supplementary group only applies to
        // a NEW login, so a box fixed that way still fails the very deploy that fixed it. Taking
        // ownership works on the connection that is already open.
        //
        // RECURSIVE for both: each directory already holds files from the previous deploy, owned
        // by www-data. Owning only the directory lets the build create new entries and still fail
        // to overwrite every existing one — which is how this first appeared, as a `mkdir` error
        // followed by a `file_put_contents` error on every asset.
        $this->takeOwnership($ssh, $vaultDir, recursive: true);
        $this->takeOwnership($ssh, $public, recursive: true);

        // Nothing is built until the vault is proven usable — see assertVaultUsable(). The old
        // check here was `test -r` alone, which passes on a zero-length file and on `{}`: the build
        // then succeeds with every secret left as its own placeholder token, and the release fails
        // at the first email or query instead of here.
        $this->assertVaultUsable($app, $ssh, $vaultDir, $staging);

        try {
            foreach ($this->buildCommands($app, $source) as $command) {
                $ssh->exec('cd ' . escapeshellarg($staging) . ' && ' . $command, timeout: 900);
            }
        } finally {
            // public/ goes back: the app serves it and writes uploads into it at runtime, so it
            // must not stay owned by the deploy account. vault/ deliberately does NOT go back —
            // the build is its only reader, and handing it to www-data is what made the previous
            // deploy fail on a file it could no longer open.
            //
            // `enforce: false` so a failed chown cannot mask the build error that caused it, but
            // it must not pass silently either.
            $handback = $ssh->sudo('chown -R www-data:www-data ' . escapeshellarg($public), enforce: false);
            if (!$handback->ok()) {
                $this->log("WARNING: could not return $public to www-data — the compiled assets are "
                    . 'still owned by the deploy user.');
            }
        }
    }

    /**
     * The only destructive moment. It is two commands rather than one so the window in which the
     * release directory does not exist is as short as a rename — under `rotate` that directory
     * is not serving anything anyway, under `restart` it is the outage.
     */
    private function swap(SshService $ssh, string $staging, string $release): void
    {
        $ssh->sudo('rm -rf ' . escapeshellarg($release));
        $ssh->sudo('mv ' . escapeshellarg($staging) . ' ' . escapeshellarg($release));
        $ssh->sudo('chown -R www-data:www-data ' . escapeshellarg($release));
    }

    private function migrate(SshService $ssh, string $release): void
    {
        $ssh->exec('cd ' . escapeshellarg($release) . ' && php fluffy migrate', timeout: 900);
    }

    /**
     * The build, as the source describes it or as this project builds by default.
     *
     * @return string[]
     */
    private function buildCommands(DeployApplicationEntity $app, DeploySourceEntity $source): array
    {
        if ($source->BuildCommand !== null && trim($source->BuildCommand) !== '') {
            return array_values(array_filter(array_map('trim', preg_split("/\r\n|\n|\r/", $source->BuildCommand))));
        }
        return [
            'composer install --no-dev --optimize-autoloader --no-interaction --no-progress',
            'cd viewi-app/js && npm ci --no-audit --no-fund',
            'php fluffy build ' . ($app->BuildParams ?: $app->Environment ?: 'prod'),
        ];
    }

    // ------------------------------------------------------------------ rows

    /** @param DeployServiceEntity[] $services @return string[] the distinct slots, in row order */
    private function slots(array $services): array
    {
        $slots = [];
        foreach ($services as $service) {
            $slot = $this->slotOf($service);
            if ($slot !== null && !in_array($slot, $slots, true)) {
                $slots[] = $slot;
            }
        }
        return $slots;
    }

    private function slotOf(DeployServiceEntity $service): ?string
    {
        $slot = trim((string) $service->Slot);
        return $slot !== '' ? $slot : null;
    }

    /** @return DeployServiceEntity[] */
    private function slotServices(array $services, string $slot): array
    {
        return array_values(array_filter($services, fn($service) => $this->slotOf($service) === $slot));
    }

    private function serviceFor(array $services, int $upstreamId, string $slot): ?DeployServiceEntity
    {
        foreach ($services as $service) {
            if ((int) $service->UpstreamId === $upstreamId && $this->slotOf($service) === $slot) {
                return $service;
            }
        }
        return null;
    }

    private function otherSlot(array $slots, string $slot): string
    {
        foreach ($slots as $candidate) {
            if ($candidate !== $slot) {
                return $candidate;
            }
        }
        throw new DeployException("There is no slot to rotate to — only '$slot' is configured.", 'preflight');
    }

    /**
     * Which slot is serving, per the upstreams themselves.
     *
     * The conf file is the truth, not `DeployApplication.LiveSlot`: a column that disagreed with
     * nginx would be worse than no column at all. Null means nothing recognisable is live — a
     * fresh box, or a conf pointing at a port no service row claims.
     */
    private function liveSlot(BlueGreenRotator $rotator, array $services, array $upstreams): ?string
    {
        foreach ($upstreams as $upstream) {
            $port = $rotator->readUpstreamPort($upstream->confPath());
            if ($port === null) {
                continue;
            }
            foreach ($services as $service) {
                if ($service->Port === $port && (int) $service->UpstreamId === (int) $upstream->Id) {
                    return $this->slotOf($service);
                }
            }
        }
        return null;
    }

    /**
     * What rotation needs to be true of the rows before anything runs.
     *
     * @param DeployServiceEntity[] $services
     * @param DeployUpstreamEntity[] $upstreams
     */
    private function assertRotatable(DeployApplicationEntity $app, array $services, array $upstreams): void
    {
        $slots = $this->slots($services);
        if (count($slots) < 2) {
            throw new DeployException(
                "'{$app->Name}' rotates but has " . (count($slots) === 0 ? 'no slots' : "only the '{$slots[0]}' slot")
                    . ' — blue/green needs two, or switch the strategy to restart.',
                'preflight'
            );
        }
        if (count($upstreams) === 0) {
            throw new DeployException(
                "'{$app->Name}' rotates but has no upstreams — there is nothing to move traffic with.",
                'preflight'
            );
        }
        foreach ($upstreams as $upstream) {
            foreach ($slots as $slot) {
                if ($this->serviceFor($services, $upstream->Id, $slot) === null) {
                    throw new DeployException(
                        "Upstream '{$upstream->Name}' has no service in the '$slot' slot — a rotation "
                            . 'would leave it pointing at the old release.',
                        'preflight'
                    );
                }
            }
        }
        foreach ($services as $service) {
            if ($this->slotOf($service) !== null && $service->Port <= 0) {
                throw new DeployException(
                    "Service '{$service->Name}' has no port — the upstream would have nothing to point at.",
                    'preflight'
                );
            }
        }
    }

    // ------------------------------------------------------------------ paths

    /** The directory that holds the release(s) — and the vault and the live-slot marker with it. */
    private function baseFolder(DeployApplicationEntity $app): string
    {
        $base = rtrim((string) $app->RootFolder, '/');
        if ($base === '') {
            throw new DeployException("Application '{$app->Name}' has no root folder.", 'preflight');
        }
        $sub = trim((string) $app->Subfolder, " /\t\n\r");
        return $sub !== '' ? "$base/$sub" : $base;
    }

    /**
     * Where the code lives under `restart`: the base folder itself, as in server-admin.
     *
     * KNOWN TRAP (kept as is on purpose): under `restart` the release IS RootFolder (+ Subfolder),
     * and swap() `rm -rf`s it. On a blue/green box the RootFolder (e.g. /workspace/myapp) is
     * the base that also holds blue/, green/, public/, files/ and the live-color marker, so the
     * FIRST deploy there must use `rotate`; `restart` only fits a root that holds the code alone.
     */
    private function appFolder(DeployApplicationEntity $app): string
    {
        return $this->baseFolder($app);
    }

    /**
     * Where a slot's release lives under `rotate`. The directory NAME is the slot — that is what
     * a running instance reads to know which one it is (`LiveInstanceService::currentColor()`).
     */
    private function slotFolder(DeployApplicationEntity $app, string $slot): string
    {
        return $this->baseFolder($app) . '/' . $slot;
    }

    /**
     * `../vault`, a sibling of the application directory — where the build reads its secrets.
     *
     * Under `rotate` the application directories are the slots, so the vault sits in the base
     * folder next to them; under `restart` the base folder IS the application, so the vault goes
     * beside it. Either way it is one level up from the code, and survives a release swap.
     */
    private function vaultDir(DeployApplicationEntity $app): string
    {
        $base = $this->baseFolder($app);
        return $this->isRotating($app) ? "$base/vault" : rtrim(dirname($base), '/') . '/vault';
    }

    /** Explicit name wins; otherwise the `values.<env>.json` the build looks for by default. */
    private function vaultFileName(DeployApplicationEntity $app): string
    {
        $name = trim((string) $app->KeyVaultFile);
        return $name !== '' ? basename($name) : 'values.' . ($app->Environment ?: 'prod') . '.json';
    }

    /**
     * The environment name the BUILD will use — which is what decides the file name it reads.
     *
     * `php fluffy build <env>` takes it from BuildParams, falling back to Environment; getVaultKeys
     * then reads `../vault/values.<that>.json`. Deriving it here rather than trusting Environment is
     * the difference between checking the file the build opens and checking a different one.
     */
    private function buildEnvironment(DeployApplicationEntity $app): string
    {
        $params = trim((string) $app->BuildParams);
        if ($params !== '') {
            // Only the first token is the environment; anything after it is a flag.
            return preg_split('/\s+/', $params)[0];
        }
        return $app->Environment ?: 'prod';
    }

    // ------------------------------------------------------------------ vault

    /** The user the app's units run as and owns its workspace (configs/server.php `service_user`; swap() chowns to it). */
    private const APP_USER = 'www-data';

    /**
     * Keys the build refuses to run without. Mirrors `$requiredVaultKeys` in configs/build.php —
     * checked here as well so the deploy fails at the preflight, with a message about the vault,
     * rather than in the middle of a remote build log.
     */
    private const VAULT_REQUIRED_KEYS = ['HASH_SALT', 'PG_DB', 'PG_USER', 'PG_PASSWORD'];

    /**
     * Prove the vault is there, readable BY THIS LOGIN, and actually carries values — before the
     * build (and therefore before the swap) can start.
     *
     * Every failure mode below has been seen on a real box, and they are indistinguishable in the
     * build's own output ("File not found" for all of them):
     *
     *  - the file is missing, because it was never pushed;
     *  - the file is THERE but this login cannot read it — a 0600 file owned by someone else, or a
     *    0770 directory this login is not in the group of. `file_exists()` is false in that case
     *    too, which is what makes it look like an absence. The usual cause is a re-run of
     *    prod/install.sh: it `chown -R www-data`s the whole workspace, and only restores group
     *    access to the vault when it is given the deployer's login name;
     *  - the file is empty or truncated, because a transfer failed after creating it;
     *  - the file is `{}` or a half-filled placeholder — valid JSON, no secrets. The build then
     *    substitutes nothing and the release runs with 'email_host' as its SMTP host.
     *
     * `sudo` is used only to describe what is there when this login cannot see it; the check itself
     * is deliberately made as the login user, because that is who the build runs as.
     *
     * Called from build(), AFTER the ownership handover and before the build commands — not earlier.
     * Earlier would be nicer (the clone would not happen) but wrong: takeOwnership() chowns the
     * vault directory to the login user and legitimately FIXES some of the permission cases below,
     * so a check in front of it would refuse boxes that work. Nothing destructive or live-facing has
     * run by this point either way — the build, the migration, the swap and the rotation are all
     * after it, so an abort here leaves the serving release exactly as it was.
     */
    private function assertVaultUsable(
        DeployApplicationEntity $app,
        SshService $ssh,
        string $vaultDir,
        string $staging
    ): void {
        $expected = $this->vaultFileName($app);
        $wanted = 'values.' . $this->buildEnvironment($app) . '.json';
        if (trim((string) $app->KeyVaultFile) === '' && $expected !== $wanted) {
            // Environment and BuildParams disagree, so the push wrote one name and the build reads
            // another. Both files can exist, and then the build silently uses the stale one.
            $this->log("WARNING: the key vault is pushed as '$expected' but `php fluffy build "
                . $this->buildEnvironment($app) . "` reads '$wanted' — set KeyVaultFile, or make "
                . 'BuildParams and Environment agree.');
        }
        $vault = $vaultDir . '/' . $wanted;

        // A vault inside the release tree is a vault the deploy itself deletes: fetch() and swap()
        // both `rm -rf` those paths. It survives only as a SIBLING of the release.
        $release = str_ends_with($staging, '_staging') ? substr($staging, 0, -strlen('_staging')) : $staging;
        foreach ([$staging, $release] as $doomed) {
            if (str_starts_with($vaultDir . '/', rtrim($doomed, '/') . '/')) {
                throw new DeployException(
                    "The key vault directory $vaultDir is INSIDE $doomed, which this deploy deletes and "
                        . 'replaces — the vault would be destroyed by the release it is meant to configure. '
                        . 'Check RootFolder / Subfolder on the application.',
                    'build'
                );
            }
        }

        if (!$ssh->test('test -d ' . escapeshellarg($vaultDir))) {
            throw new DeployException(
                "The key vault directory $vaultDir "
                    . ($ssh->sudo('test -d ' . escapeshellarg($vaultDir), enforce: false)->ok()
                        ? 'cannot be entered by the deploy login (permissions), even though it exists.'
                        : 'does not exist.')
                    . ' Run prod/10-workspace.sh <base> <owner> <deploy-login> on the server, then push '
                    . 'the key vault.',
                'build'
            );
        }

        if (!$ssh->test('test -r ' . escapeshellarg($vault))) {
            // Ask a privileged shell what is actually on disk — the whole point is that this login
            // cannot see it, so its own answer is "nothing here".
            $listing = trim($ssh->sudo('ls -la ' . escapeshellarg($vaultDir), enforce: false)->text());
            $exists = $ssh->sudo('test -f ' . escapeshellarg($vault), enforce: false)->ok();
            throw new DeployException(
                $exists
                    ? "$vault EXISTS but the deploy login cannot read it — this is a permission problem, "
                    . 'not a missing file. Most likely prod/install.sh was re-run and chowned the '
                    . 'workspace to the app user: re-run prod/10-workspace.sh <base> <owner> '
                    . '<deploy-login> (it restores group access), or push the key vault again.'
                    . ($listing !== '' ? PHP_EOL . $listing : '')
                    : "$vault does not exist — push the key vault to this server before deploying."
                    . ($listing !== '' ? PHP_EOL . $listing : ''),
                'build'
            );
        }

        // Content. Run through PHP rather than shell tests because the build's own reader is PHP,
        // and because "valid JSON with values in it" is not something `test` can answer. The script
        // prints nothing from the file itself — it is all secrets, and this output is logged.
        $checker = <<<'PHP'
            $c = @file_get_contents($argv[1]);
            if ($c === false) { echo "ERR cannot be read"; exit(1); }
            if (trim($c) === "") { echo "ERR is EMPTY (" . strlen($c) . " bytes)"; exit(1); }
            $v = json_decode($c, true);
            if (!is_array($v)) { echo "ERR is not valid JSON: " . json_last_error_msg(); exit(1); }
            if ($v === []) { echo "ERR has NO VALUES ({} - the placeholder a fresh install writes)"; exit(1); }
            $missing = [];
            foreach (explode(",", $argv[2]) as $k) {
                if (!array_key_exists($k, $v) || $v[$k] === null || $v[$k] === "" || $v[$k] === []) { $missing[] = $k; }
            }
            if ($missing) { echo "ERR has no value for: " . implode(", ", $missing); exit(1); }
            echo "OK " . count($v) . " keys, " . strlen($c) . " bytes";
            PHP;
        $result = $ssh->exec(
            'php -r ' . escapeshellarg($checker) . ' ' . escapeshellarg($vault) . ' '
                . escapeshellarg(implode(',', self::VAULT_REQUIRED_KEYS)),
            enforce: false
        );
        $answer = trim($result->text());
        if (!$result->ok() || !str_starts_with($answer, 'OK')) {
            throw new DeployException(
                "The key vault $vault " . (str_starts_with($answer, 'ERR ') ? substr($answer, 4) : "could not be "
                    . "checked: $answer")
                    . '. Deployment aborted — a build with no secrets writes a config in which every '
                    . 'secret is its own placeholder token. Push the key vault again (Deploy -> Key vault) '
                    . 'and check the values are complete.',
                'build'
            );
        }
        $this->log("Key vault $vault: $answer");
    }

    /**
     * Hand a directory to the login user so the clone and the build can write in it.
     * `$(id -un)` rather than `$USER`, which a non-interactive shell does not set.
     */
    private function takeOwnership(SshService $ssh, string $directory, bool $recursive = false): void
    {
        $ssh->sudo('mkdir -p ' . escapeshellarg($directory));
        $ssh->sudo(
            'chown ' . ($recursive ? '-R ' : '') . '"$(id -un):$(id -gn)" ' . escapeshellarg($directory),
            enforce: false
        );
    }

    // ------------------------------------------------------------------ plumbing

    /**
     * Deploying is a DEV-ONLY capability: the control plane is the local instance, production is
     * deployed *to* and never *from*.
     *
     * Checked here rather than only by skipping the DI registration, because the container
     * auto-wires concrete classes whose dependencies resolve — and the repositories are
     * deliberately registered everywhere so migrations work on prod. Not registering this class
     * therefore prevents nothing; this does.
     */
    private function assertEnabled(): void
    {
        if (empty($this->config->values['devMode'])) {
            throw new DeployException(
                'Deployments run from a local instance only — this build is not a deploy control plane.',
                'preflight'
            );
        }
    }

    private function isRotating(DeployApplicationEntity $app): bool
    {
        return ($app->Strategy ?: 'rotate') === 'rotate';
    }

    private function connect(
        DeployApplicationEntity $app,
        ?DeploySourceEntity $source,
        DeployServerEntity $server
    ): SshService {
        $ssh = new SshService(sys_get_temp_dir());
        // Registered BEFORE connecting, so nothing can reach the transcript ahead of the mask.
        $ssh->addSecret($source?->GitAccessToken);
        $ssh->addSecret($app->KeyVault);
        $ssh->addSecret($server->FileKeyPassphrase);
        $ssh->addSecret($server->Password);
        // The secret values too: a password echoed on its own (a failing psql) is not the whole
        // document. ONLY secrets: masking is applied to the output the code parses as well, so a
        // plain value (a database name, 127.0.0.1) masked there would turn paths and upstream ports
        // into stars.
        $values = $app->KeyVault !== null ? json_decode($app->KeyVault, true) : null;
        if (is_array($values)) {
            $walk = function (array $node) use (&$walk, $ssh) {
                foreach ($node as $key => $value) {
                    if (is_array($value)) {
                        $walk($value);
                    } elseif (is_string($value) && strlen($value) >= 6
                        && preg_match('/pass|secret|salt|token|_key$|auth|private/i', (string) $key)) {
                        $ssh->addSecret($value);
                    }
                }
            };
            $walk($values);
        }
        $ssh->connect(
            new SshCredentials(
                host: (string) $server->Host,
                userName: (string) $server->UserName,
                port: $server->Port,
                privateKey: $server->PrivateFileKey,
                publicKey: $server->PublicFileKey,
                passphrase: $server->FileKeyPassphrase,
                sudoPassword: $server->Password,
                label: $server->Name,
            ),
            fn(string $chunk) => $this->emit($chunk)
        );
        return $ssh;
    }

    /**
     * The history row is written BEFORE anything runs: a deploy may stop the very instance
     * executing it, and a row that only appeared at the end would be missing exactly when it is
     * needed. `IsSuccessful` stays null meanwhile, which is what "still running" looks like.
     */
    private function startRecord(
        DeployApplicationEntity $app,
        DeployServerEntity $server,
        ?DeploySourceEntity $source,
        string $kind,
        array $context
    ): DeploymentHistoryEntity {
        $record = new DeploymentHistoryEntity();
        $record->ApplicationId = $app->Id;
        $record->ServerId = $server->Id;
        $record->SourceId = $source?->Id;
        $record->Kind = $kind;
        $record->TriggeredByName = $context['userName'] ?? null;
        $record->StartedOn = DeploymentHistoryRepository::getTime();
        $this->history->create($record);
        $this->recordLogOffset = strlen($this->logs);
        $this->log("== {$kind}: {$app->Name} -> {$server->Name} ({$server->Host})");
        return $record;
    }

    private function finish(DeploymentHistoryEntity $record, ?SshService $ssh): void
    {
        if ($ssh !== null) {
            $ssh->dispose();
        }
        $record->CompletedOn = DeploymentHistoryRepository::getTime();
        // Only this server's share of the run: deploying to three boxes writes three rows, and a
        // row carrying the previous server's output would be read as this server's. The live tail
        // (`getLogs()`, the onLog stream) still shows the whole run.
        // Already scrubbed of every registered secret by SshService.
        $record->Logs = substr($this->logs, $this->recordLogOffset);
        $this->history->update($record, [
            'IsSuccessful', 'Error', 'Logs', 'CommitSha', 'CommitMessage', 'FromSlot', 'ToSlot', 'CompletedOn',
        ]);
    }

    /** Credentials go in the URL so nothing has to answer an interactive git prompt. */
    private function repoUrl(DeploySourceEntity $source): string
    {
        $url = (string) $source->GitRepository;
        if ($source->GitAccessToken === null || $source->GitAccessToken === '' || !str_starts_with($url, 'https://')) {
            return $url;
        }
        $user = $source->GitUser !== null && $source->GitUser !== ''
            ? rawurlencode($source->GitUser)
            : 'x-access-token';
        return 'https://' . $user . ':' . rawurlencode($source->GitAccessToken) . '@' . substr($url, strlen('https://'));
    }

    private function requireApplication(int $applicationId): DeployApplicationEntity
    {
        /** @var DeployApplicationEntity $app */
        $app = $this->applications->getById($applicationId);
        if (!$app) {
            throw new DeployException("Application $applicationId does not exist.", 'preflight');
        }
        return $app;
    }

    private function requireSource(DeployApplicationEntity $app): DeploySourceEntity
    {
        if (!$app->SourceId) {
            throw new DeployException("Application '{$app->Name}' has no source configured.", 'preflight');
        }
        /** @var DeploySourceEntity $source */
        $source = $this->sources->getById($app->SourceId);
        if (!$source) {
            throw new DeployException("Source {$app->SourceId} does not exist.", 'preflight');
        }
        if ($source->Type !== 'git') {
            throw new DeployException("Source '{$source->Name}' is not a git source.", 'preflight');
        }
        if (trim((string) $source->GitRepository) === '') {
            throw new DeployException("Source '{$source->Name}' has no repository URL.", 'preflight');
        }
        return $source;
    }

    /** @return \SaasPaws\Deploy\Deploy\Data\Entities\DeployApplicationServerEntity[] */
    private function serversFor(int $applicationId): array
    {
        $links = $this->links->getApplicationServers($applicationId, $this->servers);
        $links = array_values(array_filter($links, fn($link) => $link->Server !== null));
        if (count($links) === 0) {
            throw new DeployException('This application has no servers attached.', 'preflight');
        }
        return $links;
    }

    /** @return DeployServiceEntity[] */
    private function servicesFor(int $applicationId): array
    {
        return $this->services->search(
            [[DeployServiceEntityMap::PROPERTY_ApplicationId, $applicationId]],
            [DeployServiceEntityMap::PROPERTY_DisplayOrder => 1, DeployServiceEntityMap::PROPERTY_Id => 1],
            1,
            null,
            false
        )['list'];
    }

    /** @return DeployUpstreamEntity[] */
    private function upstreamsFor(int $applicationId): array
    {
        return $this->upstreams->search(
            [[DeployUpstreamEntityMap::PROPERTY_ApplicationId, $applicationId]],
            [DeployUpstreamEntityMap::PROPERTY_DisplayOrder => 1, DeployUpstreamEntityMap::PROPERTY_Id => 1],
            1,
            null,
            false
        )['list'];
    }

    /**
     * A line of our own, always on a line of its own: command output that did not end in a newline
     * would otherwise get this line glued to its end (and a long output line is cut short in the
     * UI, taking the glued line with it).
     */
    private function log(string $line): void
    {
        $start = $this->logs !== '' && !str_ends_with($this->logs, "\n") ? "\n" : '';
        $this->emit($start . $line . "\n");
    }

    private function emit(string $chunk): void
    {
        $this->logs .= $chunk;
        if ($this->onLog !== null) {
            ($this->onLog)($chunk);
        }
    }
}
