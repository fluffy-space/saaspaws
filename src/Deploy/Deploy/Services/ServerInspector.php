<?php

namespace SaasPaws\Deploy\Deploy\Services;

use SaasPaws\Deploy\Deploy\Data\Entities\DeployApplicationEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployServerEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployServiceEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeploySourceEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployUpstreamEntity;
use SaasPaws\Deploy\Ssh\SshService;
use Composer\InstalledVersions;
use Throwable;

/**
 * "Check server": what an application needs on a box, what is already there, and what of
 * someone else's is in the way. It changes no configuration and installs, reloads or restarts
 * nothing: every command reads (sudo only to read what the login cannot, e.g. PostgreSQL's
 * catalogue and nginx's config test). The side effects left are the ones reading has: `nginx -t`
 * as root may create a missing log file, and a wrong stored sudo password is one failed sudo
 * attempt in the box's auth log. Safe on a box that runs other live applications.
 *
 * Each finding has a status:
 * - ok        present and usable;
 * - missing   a prerequisite the box lacks (setting up would fail);
 * - conflict  something that is not this application's is in its way (a port, a domain, ...);
 * - exists    this application's own piece is already there (normal when re-checking);
 * - warn      worth reading, does not block;
 * - info      a fact for the reader;
 * - error     a probe could not run, so nothing is known about it (blocks, like missing).
 *
 * Paths follow what Fluffy's own builders write (vendor/fluffy-space/fluffy/bin): units in
 * /etc/systemd/system/<unit>.service, the upstream in /etc/nginx/conf.d/10-upstream-<name>.conf,
 * the vhost in /etc/nginx/sites-available/<domain> linked from sites-enabled, the shared
 * /etc/nginx/snippets/fluffy-security-headers.conf, the self-signed pair until a real certificate.
 */
class ServerInspector
{
    public const OK = 'ok';
    public const MISSING = 'missing';
    public const CONFLICT = 'conflict';
    public const EXISTS = 'exists';
    public const WARN = 'warn';
    public const INFO = 'info';
    /** A probe that could not run: nothing is known, so it blocks like a missing piece. */
    public const ERROR = 'error';

    /** PHP extensions a Fluffy app loads: Swoole, its PostgreSQL (PDO) and Redis pools, mbstring. */
    private const PHP_EXTENSIONS = ['swoole', 'pdo_pgsql', 'redis', 'mbstring', 'openssl'];

    /** The base folder may hold these (our layout) and nothing else, or setup refuses to touch it. */
    public const LAYOUT = ['blue', 'green', 'blue_staging', 'green_staging', 'vault', 'public', 'files', 'logs', 'private', 'geoip', 'live-color', 'workers'];

    /** @var list<array{status: string, group: string, label: string, detail: string, setup: bool}> */
    private array $findings = [];
    /** Whether `sudo true` actually ran: a stored password can be wrong, which canSudo() cannot see. */
    private bool $sudo = false;
    private $log;

    /**
     * @param DeployServiceEntity[] $services
     * @param DeployUpstreamEntity[] $upstreams
     * @param array<string, mixed>|null $vault the decoded key vault, or null when there is none
     * @param callable(string): void $log
     * @return list<array{status: string, group: string, label: string, detail: string, setup: bool}>
     */
    public function inspect(
        SshService $ssh,
        DeployApplicationEntity $app,
        ?DeploySourceEntity $source,
        DeployServerEntity $server,
        array $services,
        array $upstreams,
        ?array $vault,
        string $baseFolder,
        string $vaultPath,
        callable $log
    ): array {
        $this->findings = [];
        $this->log = $log;
        $this->sudo = false;
        // Each probe is on its own: one that fails to run is a finding, not the end of the check.
        foreach ([
            fn() => $this->system($ssh, $baseFolder),
            fn() => $this->privileges($ssh),
            fn() => $this->php($ssh),
            fn() => $this->tools($ssh),
            fn() => $this->source($ssh, $source),
            fn() => $this->postgres($ssh, $vault),
            fn() => $this->redis($ssh),
            fn() => $this->workspace($ssh, $app, $baseFolder, $vaultPath, $vault),
            fn() => $this->units($ssh, $services),
            fn() => $this->webServer($ssh, $app, $upstreams),
            fn() => $this->dns($ssh, $app, $server),
            fn() => $this->mail($ssh, $vault),
        ] as $probe) {
            try {
                $probe();
            } catch (Throwable $e) {
                $this->add(self::ERROR, 'check', 'a probe failed to run', $e->getMessage());
            }
        }
        return $this->findings;
    }

    /** True when nothing blocks setting the application up. */
    public static function ready(array $findings): bool
    {
        return self::blockers($findings) === [];
    }

    /**
     * What stops Set up: anything missing, in the way or unknown, and the warnings that would only
     * surface at its web-server step, after the app has started (a config that already fails its
     * test, no includes, no self-signed pair, a shared snippet ours would change).
     *
     * @return list<array{status: string, group: string, label: string, detail: string, setup: bool}>
     */
    public static function blockers(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            fn($f) => in_array($f['status'], [self::MISSING, self::CONFLICT, self::ERROR], true) || $f['setup']
        ));
    }

    // ------------------------------------------------------------------ probes

    private function system(SshService $ssh, string $baseFolder): void
    {
        $os = $this->read($ssh, '. /etc/os-release 2>/dev/null && echo "$PRETTY_NAME"; uname -r');
        $this->add(self::INFO, 'system', 'OS', str_replace("\n", ', kernel ', $os));
        $this->add(self::INFO, 'system', 'CPU cores', $this->read($ssh, 'nproc'));
        $mem = $this->read($ssh, "free -m | awk '/^Mem:/ {print \$2\" MB total, \"\$7\" MB available\"}'");
        $this->add(self::INFO, 'system', 'memory', $mem);
        // The nearest existing directory on the way to the base folder: that is the disk it lands on.
        $probe = 'd=' . escapeshellarg($baseFolder) . '; while [ ! -d "$d" ]; do d=$(dirname "$d"); done; '
            . "df -Pm \"\$d\" | awk 'NR==2 {print \$4\" MB free on \"\$6}'";
        $free = $this->read($ssh, $probe);
        $mb = (int) $free;
        $this->add($mb > 0 && $mb < 2048 ? self::WARN : self::INFO, 'system', 'disk', $free . ($mb > 0 && $mb < 2048 ? ' (a build needs room: composer, npm, two releases)' : ''));
        $this->add(self::INFO, 'system', 'time zone', $this->read($ssh, "timedatectl show -p Timezone --value 2>/dev/null || cat /etc/timezone 2>/dev/null"));
        // Every timestamp the app records (rounds, incidents, logs) is only as right as this clock.
        $synced = $this->read($ssh, 'timedatectl show -p NTPSynchronized --value 2>/dev/null');
        $this->add($synced === 'yes' ? self::OK : self::WARN, 'system', 'clock', $synced === 'yes'
            ? 'synchronised (NTP)'
            : 'not reported as NTP-synchronised: recorded times may drift');
    }

    private function privileges(SshService $ssh): void
    {
        // Tried ONCE: with a wrong stored password every attempt is a logged failed login.
        $possible = $ssh->canSudo();
        $this->sudo = $possible && $ssh->sudo('true', false, 20)->ok();
        if ($this->sudo) {
            $this->add(self::OK, 'access', 'sudo', 'privileged commands can run');
        } else {
            $this->add(self::MISSING, 'access', 'sudo', $possible
                ? 'sudo refused: is the stored password right?'
                : 'this login cannot sudo: no root login, no sudo password stored, and no NOPASSWD rule');
        }
    }

    private function php(SshService $ssh): void
    {
        $result = $ssh->exec(
            // Startup warnings go to stderr, and each line we parse carries a prefix, so a stray
            // "PHP Warning" on stdout cannot shift what is read.
            "php -d display_startup_errors=0 -d display_errors=stderr -r 'echo \"V:\", PHP_VERSION, \" \", PHP_ZTS ? \"zts\" : \"nts\", \" \", phpversion(\"swoole\") ?: \"-\", \"\\nE:\", implode(\",\", array_map(\"strtolower\", get_loaded_extensions())), \"\\n\";'",
            false,
            30
        );
        if (!$result->ok()) {
            $this->add(self::MISSING, 'php', 'PHP CLI', 'php is not on the PATH');
            return;
        }
        $versionLine = '';
        $extensionLine = '';
        foreach (explode("\n", $result->output) as $line) {
            if (str_starts_with($line, 'V:')) {
                $versionLine = substr($line, 2);
            } elseif (str_starts_with($line, 'E:')) {
                $extensionLine = substr($line, 2);
            }
        }
        [$version, $zts, $swoole] = array_pad(explode(' ', trim($versionLine)), 3, '-');
        $loaded = explode(',', trim($extensionLine));
        $this->add(version_compare($version, '8.3', '>=') ? self::OK : self::MISSING, 'php', 'PHP', "$version $zts (8.3 or newer needed)");
        $this->add($swoole !== '-' ? self::OK : self::MISSING, 'php', 'Swoole', $swoole !== '-' ? $swoole : 'the swoole extension is not loaded');
        $absent = array_values(array_diff(self::PHP_EXTENSIONS, $loaded));
        $this->add($absent === [] ? self::OK : self::MISSING, 'php', 'extensions', $absent === []
            ? implode(', ', self::PHP_EXTENSIONS)
            : 'not loaded: ' . implode(', ', $absent));
        $local = PHP_VERSION . ' ' . (PHP_ZTS ? 'zts' : 'nts') . ', Swoole ' . (phpversion('swoole') ?: '-');
        $this->add(self::INFO, 'php', 'compared with this control box', $local);
    }

    private function tools(SshService $ssh): void
    {
        foreach (['git' => true, 'composer' => true, 'node' => true, 'npm' => true, 'certbot' => false] as $tool => $required) {
            $where = $ssh->exec('command -v ' . $tool . ' && ' . $tool . ' --version 2>/dev/null | head -1', false, 30);
            if ($where->ok()) {
                $lines = explode("\n", trim($where->output));
                $this->add(self::OK, 'tools', $tool, trim(($lines[1] ?? '') . ' (' . ($lines[0] ?? '') . ')'));
            } else {
                $this->add($required ? self::MISSING : self::WARN, 'tools', $tool, $required
                    ? 'not installed: the deploy needs it'
                    : 'not installed: needed only to issue a Let\'s Encrypt certificate');
            }
        }
    }

    /** The box clones the release itself, so it must reach the repository and the branch must exist. */
    private function source(SshService $ssh, ?DeploySourceEntity $source): void
    {
        if ($source === null || trim((string) $source->GitRepository) === '') {
            $this->add(self::MISSING, 'source', 'repository', 'the application has no deploy source');
            return;
        }
        $url = (string) $source->GitRepository;
        $token = (string) $source->GitAccessToken;
        if ($token !== '' && str_starts_with($url, 'https://')) {
            $user = $source->GitUser ? rawurlencode($source->GitUser) : 'x-access-token';
            $url = 'https://' . $user . ':' . rawurlencode($token) . '@' . substr($url, 8);
        }
        $branch = $source->GitBranch ?: 'main';
        $ref = 'refs/heads/' . $branch;
        $result = $ssh->exec('GIT_TERMINAL_PROMPT=0 git ls-remote ' . escapeshellarg($url) . ' ' . escapeshellarg($ref), false, 60);
        // ls-remote matches by suffix (refs/heads/feature/main for "main"): compare the ref exactly.
        $found = false;
        foreach (explode("\n", trim($result->output)) as $line) {
            if (preg_match('/^[0-9a-f]{40}\t' . preg_quote($ref, '/') . '$/', trim($line))) {
                $found = true;
            }
        }
        if (!$result->ok()) {
            $this->add(self::MISSING, 'source', 'repository', 'the box cannot read ' . $source->GitRepository . ' (token, network or git): ' . $this->first($result->stderr));
        } elseif (!$found) {
            $this->add(self::MISSING, 'source', "branch $branch", "no branch '$branch' in " . $source->GitRepository);
        } else {
            $this->add(self::OK, 'source', "branch $branch", 'reachable from the box at ' . substr(trim($result->output), 0, 12));
        }
    }

    private function postgres(SshService $ssh, ?array $vault): void
    {
        $version = $ssh->exec('psql --version', false, 30);
        if (!$version->ok()) {
            $this->add(self::MISSING, 'postgresql', 'client', 'psql is not installed');
            return;
        }
        $this->add(self::INFO, 'postgresql', 'client', trim($version->output));
        if (!$this->sudo) {
            $this->add(self::WARN, 'postgresql', 'server', 'cannot look without sudo');
            return;
        }
        $pgPort = (int) ($vault['PG_PORT'] ?? 5432) ?: 5432;
        $q = fn(string $sql, string $db = 'postgres') => $ssh->sudo('runuser -u postgres -- psql -X -p ' . $pgPort . ' -d ' . escapeshellarg($db) . ' -tAc ' . escapeshellarg($sql), false, 30);
        $server = $q('SHOW server_version');
        if (!$server->ok()) {
            $this->add(self::MISSING, 'postgresql', 'server', 'not answering: ' . $this->first($server->text()));
            return;
        }
        $this->add(self::OK, 'postgresql', 'server', trim($server->output));
        $citext = $q("SELECT 1 FROM pg_available_extensions WHERE name = 'citext'");
        $this->add(trim($citext->output) === '1' ? self::OK : self::MISSING, 'postgresql', 'citext', trim($citext->output) === '1'
            ? 'available'
            : 'not available: install postgresql-contrib');
        $databases = $q("SELECT string_agg(datname, ', ' ORDER BY datname) FROM pg_database WHERE NOT datistemplate");
        // Two colours of a new app add their pools (~8 connections each) next to everyone else's.
        $room = $q("SELECT current_setting('max_connections')::int - (SELECT count(*) FROM pg_stat_activity)");
        $free = (int) trim($room->output);
        $this->add($free >= 30 ? self::OK : self::WARN, 'postgresql', 'connections', "$free free of max_connections" . ($free < 30 ? ': a new app with two colours may run short' : ''));
        $this->add(self::INFO, 'postgresql', 'databases on the box', trim($databases->output));

        $db = (string) ($vault['PG_DB'] ?? '');
        $user = (string) ($vault['PG_USER'] ?? '');
        if ($db === '' || $user === '') {
            $this->add(self::MISSING, 'postgresql', 'database', 'the key vault names no PG_DB / PG_USER');
            return;
        }
        foreach (['PG_DB' => $db, 'PG_USER' => $user] as $key => $name) {
            // 56: room for the "_verify" twin inside PostgreSQL's 63-byte names.
            if (!preg_match('/^[a-z_][a-z0-9_]{0,55}$/', $name) || $name === 'postgres' || str_starts_with($name, 'pg_')) {
                $this->add(self::CONFLICT, 'postgresql', $key, "'$name' is not a plain lowercase name of ours (reserved, too long or odd characters)");
                return;
            }
        }
        $ownerOf = fn(string $name) => trim($q('SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = ' . $this->literal($name))->output);
        $hasRole = trim($q('SELECT 1 FROM pg_roles WHERE rolname = ' . $this->literal($user))->output) === '1';
        $owner = $ownerOf($db);
        $verifyOwner = $ownerOf($db . '_verify');
        if ($owner === '' && $hasRole) {
            // A role with no database of ours is someone else's: Set up would reset its password.
            $this->add(self::CONFLICT, 'postgresql', "role $user", "exists but owns no '$db' database: another application's role?");
        } elseif ($owner !== '' && $owner !== $user) {
            $this->add(self::CONFLICT, 'postgresql', "database $db", "exists but is owned by '$owner', not $user: another application's database?");
        } elseif ($owner === $user) {
            $tables = trim($q("SELECT count(*) FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog', 'information_schema')", $db)->output);
            $this->add(self::EXISTS, 'postgresql', "database $db", "exists, owned by $user, $tables tables");
        } else {
            $this->add(self::OK, 'postgresql', "role $user / database $db", 'free: setup creates them');
        }
        if ($verifyOwner !== '' && $verifyOwner !== $user) {
            $this->add(self::CONFLICT, 'postgresql', "database {$db}_verify", "exists but is owned by '$verifyOwner'");
        }
    }

    private function redis(SshService $ssh): void
    {
        $ping = $ssh->exec('redis-cli PING', false, 15);
        if (str_contains($ping->text(), 'NOAUTH')) {
            // A shared Redis with a password: it is there; reading its keyspace would need the password.
            $this->add(self::OK, 'redis', 'server', 'answers, and requires a password: check its db indexes by hand');
            return;
        }
        if (!$ping->ok() || trim($ping->output) !== 'PONG') {
            $this->add(self::MISSING, 'redis', 'server', 'redis-cli PING did not answer PONG on the default port: ' . $this->first($ping->text()));
            return;
        }
        $this->add(self::OK, 'redis', 'server', trim($this->read($ssh, "redis-cli INFO server | grep -E '^redis_version' | cut -d: -f2")));
        // Fluffy's keys are not prefixed per application, so each app needs its own db index. The
        // index is in the app's configs/build.php, not here: list what is taken, for the reader.
        $keyspace = $this->read($ssh, "redis-cli INFO keyspace | grep -E '^db[0-9]+' | sed 's/,expires.*//' | tr '\\n' ' '");
        $this->add(self::INFO, 'redis', 'db indexes in use', $keyspace !== '' ? $keyspace : 'none');
    }

    private function workspace(SshService $ssh, DeployApplicationEntity $app, string $baseFolder, string $vaultPath, ?array $vault): void
    {
        $isDir = $this->sudo ? $ssh->sudo('test -d ' . escapeshellarg($baseFolder), false, 15)->ok() : $ssh->test('test -d ' . escapeshellarg($baseFolder));
        if ($isDir) {
            // As root: a folder the login cannot read would otherwise look empty.
            $list = $this->sudo ? $ssh->sudo('ls -1A ' . escapeshellarg($baseFolder), false, 15) : $ssh->exec('ls -1A ' . escapeshellarg($baseFolder), false, 15);
            if (!$list->ok()) {
                $this->add(self::ERROR, 'workspace', $baseFolder, 'exists but cannot be listed: ' . $this->first($list->text()));
                return;
            }
            $entries = array_values(array_filter(explode("\n", trim($list->output))));
            $foreign = array_values(array_diff($entries, self::LAYOUT));
            if ($foreign !== []) {
                // A shared parent (/var/www, /workspace): Set up would chown and chmod it.
                $this->add(self::CONFLICT, 'workspace', $baseFolder, 'holds more than this application\'s layout: ' . implode(', ', array_slice($foreign, 0, 10)));
            } else {
                $this->add(self::EXISTS, 'workspace', $baseFolder, $entries !== [] ? 'contains: ' . implode(' ', $entries) : 'empty');
            }
        } else {
            $this->add(self::OK, 'workspace', $baseFolder, 'free: setup creates it');
        }
        $live = $baseFolder . '/live-color';
        if ($ssh->test('test -f ' . escapeshellarg($live))) {
            $this->add(self::EXISTS, 'workspace', 'live colour', trim($this->read($ssh, 'cat ' . escapeshellarg($live))));
        }
        $onBox = $ssh->test('test -e ' . escapeshellarg($vaultPath))
            || ($this->sudo && $ssh->sudo('test -e ' . escapeshellarg($vaultPath), false, 15)->ok());
        $this->add($onBox ? self::EXISTS : self::INFO, 'workspace', 'key vault on the box', $onBox ? "$vaultPath exists" : "$vaultPath not there yet: setup pushes it");
        if ($vault === null) {
            $this->add(self::MISSING, 'workspace', 'key vault (admin)', "the application's Key vault field is empty or not JSON");
        } else {
            $this->add(self::OK, 'workspace', 'key vault (admin)', count($vault) . ' values');
        }
    }

    /** @param DeployServiceEntity[] $services */
    private function units(SshService $ssh, array $services): void
    {
        if ($services === []) {
            $this->add(self::MISSING, 'services', 'units', 'the application has no services: generate the blue/green rows first');
            return;
        }
        foreach ($services as $service) {
            $name = preg_replace('/\.service$/', '', (string) $service->Name);
            $unit = "/etc/systemd/system/$name.service";
            $hasUnit = $ssh->test('test -f ' . escapeshellarg($unit));
            $state = trim($ssh->exec('systemctl is-active ' . escapeshellarg($name) . ' 2>/dev/null', false, 15)->output);
            $this->add($hasUnit ? self::EXISTS : self::OK, 'services', "unit $name", $hasUnit ? "exists, $state" : 'free: setup writes it');

            if ($service->Port) {
                $probe = $this->sudo
                    ? $ssh->sudo('ss -ltnpH ' . escapeshellarg('sport = :' . (int) $service->Port), false, 15)
                    : $ssh->exec('ss -ltnH ' . escapeshellarg('sport = :' . (int) $service->Port), false, 15);
                if (!$probe->ok()) {
                    $this->add(self::ERROR, 'services', 'port ' . $service->Port, 'could not list listeners: ' . $this->first($probe->text()));
                    continue;
                }
                // stdout only: a warning on stderr (sudo cannot resolve the host name) is not a listener.
                $holder = trim($probe->output);
                if ($holder === '') {
                    $this->add(self::OK, 'services', 'port ' . $service->Port, 'free');
                } elseif ($state === 'active') {
                    $this->add(self::EXISTS, 'services', 'port ' . $service->Port, "in use by $name itself");
                } else {
                    $this->add(self::CONFLICT, 'services', 'port ' . $service->Port, 'in use by something else: ' . $this->first($holder));
                }
            }
        }
    }

    /** @param DeployUpstreamEntity[] $upstreams */
    private function webServer(SshService $ssh, DeployApplicationEntity $app, array $upstreams): void
    {
        $openresty = $ssh->test('test -x /usr/local/openresty/bin/openresty');
        $nginx = $ssh->test('command -v nginx');
        if (!$openresty && !$nginx) {
            $this->add(self::MISSING, 'web server', 'nginx', 'neither nginx nor OpenResty is installed');
            return;
        }
        $binary = $openresty ? '/usr/local/openresty/bin/openresty' : 'nginx';
        $this->add(self::OK, 'web server', $openresty ? 'OpenResty' : 'nginx', $this->first($ssh->exec($binary . ' -v 2>&1', false, 15)->text()));
        if ($this->sudo) {
            $test = $ssh->sudo($binary . ' -t', false, 30);
            $this->add($test->ok() ? self::OK : self::WARN, 'web server', 'config test', $test->ok()
                ? 'passes now (setup must leave it passing)'
                : 'FAILS before we touch anything: ' . $this->first($test->text()), !$test->ok());
        }
        // Fluffy writes into /etc/nginx; an OpenResty box only reads it if its main config includes it.
        $includes = trim($ssh->exec("grep -rhoE 'include[[:space:]]+[^;]*(sites-enabled|conf\\.d)[^;]*' /etc/nginx/nginx.conf /usr/local/openresty/nginx/conf/nginx.conf 2>/dev/null | sort -u | tr '\\n' ' '", false, 15)->output);
        $this->add($includes !== '' ? self::OK : self::WARN, 'web server', 'includes', $includes !== ''
            ? $includes
            : 'no include of /etc/nginx/sites-enabled or conf.d found: a vhost written there would not be read', $includes === '');

        // nginx picks the server for an unknown host name PER LISTEN SOCKET: the `default_server` on
        // it, else the first block read that listens on it. Our vhost must not become that for any
        // socket it opens (a socket nobody else listens on included: IPv6, typically), or it would
        // answer other sites' visitors. Read from the config nginx actually loads (`-T`).
        $domainName = trim((string) $app->Domain);
        if ($domainName !== '' && $this->sudo) {
            $dump = self::loadedConfig($ssh, $binary);
            $template = class_exists(InstalledVersions::class) ? InstalledVersions::getInstallPath('fluffy-space/fluffy') . '/bin/setup/nginx.conf' : '';
            $ourListens = is_file($template) ? self::listensIn((string) file_get_contents($template)) : ['443', '[::]:443', '80', '[::]:80'];
            if (!$dump->ok()) {
                $this->add(self::ERROR, 'web server', 'fallback per socket', 'could not read the loaded config (' . $this->first($dump->text()) . ')');
            } else {
                foreach (self::fallbacks($dump->output, "/etc/nginx/sites-enabled/$domainName", $ourListens) as $socket => [$safe, $detail]) {
                    $this->add($safe ? self::OK : self::WARN, 'web server', "fallback on $socket", $detail, !$safe);
                }
            }
        }

        $domain = trim((string) $app->Domain);
        if ($domain !== '') {
            $available = "/etc/nginx/sites-available/$domain";
            $mine = $ssh->test('test -e ' . escapeshellarg($available));
            $elsewhere = $ssh->exec('grep -rlE ' . escapeshellarg('server_name[^;]*[[:space:]]' . self::ere($domain) . '[;[:space:]]') . ' /etc/nginx /usr/local/openresty/nginx/conf 2>/dev/null | grep -v ' . escapeshellarg("/sites-available/$domain\$") . ' | grep -v ' . escapeshellarg("/sites-enabled/$domain\$"), false, 15)->output;
            $elsewhere = trim($elsewhere);
            if ($elsewhere !== '') {
                $this->add(self::CONFLICT, 'web server', "vhost $domain", 'another config already serves this name: ' . str_replace("\n", ', ', $elsewhere));
            } else {
                $this->add($mine ? self::EXISTS : self::OK, 'web server', "vhost $domain", $mine ? "$available exists" : 'free: setup writes it');
            }
            $cert = $this->sudo && $ssh->sudo('test -d ' . escapeshellarg("/etc/letsencrypt/live/$domain"), false, 15)->ok();
            $this->add($cert ? self::EXISTS : self::INFO, 'web server', 'certificate', $cert
                ? "Let's Encrypt certificate for $domain present"
                : 'none yet: the vhost starts on the self-signed pair; issue one when DNS points here');
        } else {
            $this->add(self::MISSING, 'web server', 'domain', 'the application has no domain');
        }
        $selfSigned = $ssh->test('test -f /etc/ssl/certs/nginx-selfsigned.crt');
        $this->add($selfSigned ? self::OK : self::WARN, 'web server', 'self-signed pair', $selfSigned
            ? 'present (the vhost uses it until a real certificate exists)'
            : '/etc/ssl/certs/nginx-selfsigned.crt missing: the new vhost would fail the config test', !$selfSigned);

        foreach ($upstreams as $upstream) {
            $path = $upstream->confPath();
            $taken = $ssh->exec('grep -rlE ' . escapeshellarg('upstream[[:space:]]+' . self::ere((string) $upstream->Name) . '[[:space:]]*\{') . ' /etc/nginx /usr/local/openresty/nginx/conf 2>/dev/null | grep -v ' . escapeshellarg($path . '$'), false, 15)->output;
            $taken = trim($taken);
            if ($taken !== '') {
                $this->add(self::CONFLICT, 'web server', "upstream {$upstream->Name}", 'already defined in: ' . str_replace("\n", ', ', $taken));
            } else {
                $exists = $ssh->test('test -e ' . escapeshellarg($path));
                $this->add($exists ? self::EXISTS : self::OK, 'web server', "upstream {$upstream->Name}", $exists ? "$path exists" : 'free: setup writes it');
            }
        }

        // Shared by every Fluffy app on the box, and rewritten by `fluffy nginx`: say so if ours differs.
        $snippet = '/etc/nginx/snippets/fluffy-security-headers.conf';
        if ($ssh->test('test -f ' . escapeshellarg($snippet))) {
            $remote = $this->read($ssh, 'sha256sum ' . escapeshellarg($snippet) . " | cut -d' ' -f1");
            $fluffy = class_exists(InstalledVersions::class) ? InstalledVersions::getInstallPath('fluffy-space/fluffy') : null;
            $localFile = $fluffy !== null ? $fluffy . '/bin/setup/nginx-security-headers.conf' : '';
            $local = is_file($localFile) ? hash_file('sha256', $localFile) : '';
            $this->add($local === '' || $local === $remote ? self::INFO : self::WARN, 'web server', 'shared security headers', $local === '' || $local === $remote
                ? "$snippet exists" . ($local === $remote ? ' and matches ours' : '')
                : "$snippet exists and DIFFERS from this Fluffy's copy: writing ours changes the headers of every site that includes it", $local !== '' && $local !== $remote);
        }
    }

    private function dns(SshService $ssh, DeployApplicationEntity $app, DeployServerEntity $server): void
    {
        $domain = trim((string) $app->Domain);
        if ($domain === '') {
            return;
        }
        $resolved = $this->read($ssh, 'getent ahostsv4 ' . escapeshellarg($domain) . " | awk '{print \$1}' | sort -u | tr '\\n' ' '");
        // The box's own addresses plus the one we connect to; no outside service is asked.
        $own = $this->read($ssh, 'hostname -I 2>/dev/null');
        $ownIps = preg_split('/\s+/', trim($own . ' ' . $server->Host));
        $hits = array_intersect(preg_split('/\s+/', trim($resolved)), $ownIps);
        if (trim($resolved) === '') {
            $this->add(self::WARN, 'dns', $domain, 'does not resolve yet: the certificate cannot be issued until it does');
        } elseif ($hits !== []) {
            $this->add(self::OK, 'dns', $domain, "points here ($resolved)");
        } else {
            $this->add(self::WARN, 'dns', $domain, "points to $resolved, not to this box");
        }
    }

    /** Can the box reach the vault's mail server? Without it every alert and notice fails quietly. */
    private function mail(SshService $ssh, ?array $vault): void
    {
        $host = trim((string) ($vault['email_host'] ?? ''));
        if ($host === '') {
            $this->add(self::INFO, 'mail', 'SMTP', 'the key vault names no email_host');
            return;
        }
        if (!preg_match('/^[a-z0-9.-]+$/i', $host)) {
            $this->add(self::WARN, 'mail', 'SMTP', "email_host '$host' is not a host name");
            return;
        }
        $port = (int) ($vault['email_port'] ?? 0) ?: (strtolower((string) ($vault['email_SMTPSecure'] ?? '')) === 'ssl' ? 465 : 587);
        $open = $ssh->exec('timeout 8 bash -c ' . escapeshellarg("exec 3<>/dev/tcp/$host/$port"), false, 15)->ok();
        $this->add($open ? self::OK : self::WARN, 'mail', "SMTP $host:$port", $open
            ? 'reachable from the box'
            : 'NOT reachable from the box (firewall, egress): alerts and incident emails would not send');
    }

    /**
     * `nginx -T`, reduced ON THE BOX to what fallbacks() reads: the "# configuration file" lines,
     * `server` and `listen` lines and the braces of everything else. The full dump is every site's
     * config (other applications' vhosts, their headers and paths): it must not reach our logs.
     */
    public static function loadedConfig(SshService $ssh, string $binary): \SaasPaws\Deploy\Ssh\SshResult
    {
        $awk = '/^# configuration file / { print; next } '
            . '{ line = $0; sub(/#.*/, "", line) } '
            . 'line ~ /^[[:space:]]*(server|listen)[[:space:];{]/ { print line; next } '
            . 'line ~ /[{}]/ { b = line; gsub(/[^{}]/, "", b); print b }';
        return $ssh->sudo('sh -c ' . escapeshellarg($binary . ' -T 2>/dev/null | awk ' . escapeshellarg($awk)), false, 60);
    }

    /** The sockets the `listen` lines of a config text open, normalised ("*:443", "[::]:443"). @return string[] */
    public static function listensIn(string $config): array
    {
        $out = [];
        foreach (preg_split('/\R/', $config) as $line) {
            $line = preg_replace('/#.*/', '', $line);
            if (preg_match('/^\s*listen\s+([^\s;]+)/', $line, $m) && ($socket = self::socket($m[1])) !== null) {
                $out[] = $socket;
            }
        }
        return array_values(array_unique($out));
    }

    /** "443" -> "*:443", "[::]:443" stays, "1.2.3.4:443" stays; a unix socket -> null. */
    public static function socket(string $listen): ?string
    {
        if (preg_match('/^\d+$/', $listen)) {
            return '*:' . $listen;
        }
        if (preg_match('/^(\[[0-9a-f:.]*\]|[0-9.]+|\*):(\d+)$/i', $listen, $m)) {
            return strtolower($m[1]) . ':' . $m[2];
        }
        return null;
    }

    /**
     * Per socket our vhost would open: is it safe, and why. $dump is `nginx -T`: each loaded file
     * after a "# configuration file <path>:" line, in the order nginx reads them. Server blocks in
     * $ours (a rerun) are ignored. Unsafe when nobody else listens on the socket, or when no
     * `default_server` covers it and our file would be read before the first other block there
     * (same directory, alphabetical; a file from an earlier include always comes first).
     *
     * @param string[] $ourSockets as listensIn() returns them
     * @return array<string, array{0: bool, 1: string}>
     */
    public static function fallbacks(string $dump, string $ours, array $ourSockets): array
    {
        $blocks = [];
        $files = [];
        $file = '';
        $depth = 0;
        $current = null;
        foreach (preg_split('/\R/', $dump) as $raw) {
            if (preg_match('/^# configuration file (.+):$/', $raw, $m)) {
                $file = $m[1];
                if ($file !== $ours) {
                    $files[] = $file;
                }
                $depth = 0;
                $current = null;
                continue;
            }
            $line = preg_replace('/#.*/', '', $raw);
            if ($current === null && preg_match('/^\s*server\s*\{/', $line) && $file !== $ours) {
                $current = ['file' => $file, 'listens' => [], 'start' => $depth];
            }
            if ($current !== null && preg_match('/^\s*listen\s+([^\s;]+)([^;]*);/', $line, $m) && ($socket = self::socket($m[1])) !== null) {
                $current['listens'][$socket] = ($current['listens'][$socket] ?? false) || str_contains($m[2], 'default_server') || str_contains($m[2], 'default ');
            }
            $depth += substr_count($line, '{') - substr_count($line, '}');
            if ($current !== null && $depth <= $current['start']) {
                $blocks[] = $current;
                $current = null;
            }
        }
        $result = [];
        foreach ($ourSockets as $socket) {
            $there = array_values(array_filter($blocks, fn($b) => array_key_exists($socket, $b['listens'])));
            if ($there === []) {
                $result[$socket] = [false, "no other site listens on $socket: ours would answer ALL traffic there, any host name"];
                continue;
            }
            $default = array_values(array_filter($there, fn($b) => $b['listens'][$socket]));
            if ($default !== []) {
                $result[$socket] = [true, 'default_server is ' . $default[0]['file']];
                continue;
            }
            $first = $there[0]['file'];
            // Where our file would be read: among its directory's files in the order nginx reads
            // them (alphabetical within the glob), placed in the dump's file order. Unknown when
            // nginx reads nothing from that directory: then it is not safe to say.
            $order = $files;
            $siblings = array_values(array_filter($order, fn($f) => dirname($f) === dirname($ours)));
            if ($siblings === []) {
                $result[$socket] = [false, 'no default_server, and nothing else is read from ' . dirname($ours) . ': cannot tell whether ours would come before ' . $first];
                continue;
            }
            $at = null;
            foreach ($siblings as $sibling) {
                if (strcmp(basename($ours), basename($sibling)) < 0) {
                    $at = array_search($sibling, $order, true);
                    break;
                }
            }
            $at ??= array_search(end($siblings), $order, true) + 0.5;
            $oursFirst = $at <= array_search($first, $order, true);
            $result[$socket] = $oursFirst
                ? [false, "no default_server, and ours would be read before $first: it would answer unknown host names"]
                : [true, "no default_server; the fallback stays $first"];
        }
        return $result;
    }

    // ------------------------------------------------------------------ helpers

    /** @param bool $blocksSetup a warning that Set up must not go past (see blockers()) */
    private function add(string $status, string $group, string $label, string $detail, bool $blocksSetup = false): void
    {
        $this->findings[] = ['status' => $status, 'group' => $group, 'label' => $label, 'detail' => trim($detail), 'setup' => $blocksSetup];
        ($this->log)(sprintf('[%s] %s: %s - %s%s', $status, $group, $label, trim($detail), $blocksSetup ? ' (blocks Set up)' : ''));
    }

    private function read(SshService $ssh, string $command): string
    {
        return trim($ssh->exec($command, false, 30)->output);
    }

    private function first(string $text): string
    {
        return trim(strtok(trim($text), "\n") ?: '');
    }

    /** Escapes what is special in a grep -E pattern (a domain's dots, mostly). */
    private static function ere(string $value): string
    {
        return preg_replace('/[.\[\]\\(){}*+?^$|]/', '\\\\$0', $value);
    }

    private function literal(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
