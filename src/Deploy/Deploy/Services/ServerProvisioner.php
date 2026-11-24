<?php

namespace SaasPaws\Deploy\Deploy\Services;

use SaasPaws\Deploy\Ssh\SshService;

/**
 * The box-level steps of "Set up" (DeploymentService::setup), each safe to run again:
 * the workspace, the PostgreSQL role and databases, the systemd units and the web server.
 *
 * Built for a box that already runs other applications: it only ever creates or changes what is
 * this application's own (its folders, its role and databases, its units, its vhost and upstream
 * file), it refuses where a name it would use belongs to someone else, and the web server is put
 * back exactly as it was if our config does not pass its test. Installing or reconfiguring shared
 * services (PHP, PostgreSQL, Redis, OpenResty itself, group memberships of the shared www-data) is
 * not its job: "Check server" reports what is missing and Set up refuses to start.
 *
 * Follows what the repo's own scripts do (prod/10-workspace.sh, Install/provision-postgres.sh,
 * `php fluffy service`, `php fluffy nginx`), so a box set up either way ends up the same, minus
 * the box-wide changes those scripts make knowingly when a person runs them.
 */
class ServerProvisioner
{
    private const OPENRESTY = '/usr/local/openresty/bin/openresty';

    /** @var callable(string): void|null */
    private $log = null;

    public function onLog(callable $log): void
    {
        $this->log = $log;
    }

    /**
     * prod/10-workspace.sh: the base folder and its siblings, owned by the app's user, with the
     * deploy login in that user's group so it can write the vault and public/ (what made the first
     * "Push vault" fail on a fresh box). Group membership applies to NEW logins: reconnect after.
     *
     * Refuses a base folder that holds anything but this layout: a shared parent such as /var/www
     * would otherwise be chowned to the app's user. Unlike the script it does NOT add the shared
     * www-data to systemd-journal: that would let every www-data service on the box read the whole
     * journal, a box-wide choice for a person to make.
     */
    public function workspace(SshService $ssh, string $base, string $owner, ?string $deployer): void
    {
        if ($ssh->sudo('test -d ' . escapeshellarg($base), false, 15)->ok()) {
            // As root: a folder the login cannot read would otherwise look empty and be chowned.
            $list = $ssh->sudo('ls -1A ' . escapeshellarg($base), false, 15);
            if (!$list->ok()) {
                throw new DeployException("$base exists but cannot be listed: not touching it.", 'workspace');
            }
            $entries = array_values(array_filter(explode("\n", trim($list->output))));
            $foreign = array_values(array_diff($entries, ServerInspector::LAYOUT));
            if ($foreign !== []) {
                throw new DeployException("$base holds more than this application's layout (" . implode(', ', $foreign) . '): not touching it.', 'workspace');
            }
        }
        $b = escapeshellarg($base);
        $o = escapeshellarg($owner);
        $script = 'set -e; '
            . "id -u $o >/dev/null; "
            . "mkdir -p $b/blue $b/green $b/vault $b/public $b/files $b/logs $b/private; "
            // Not -R: a release already there keeps what the deploy gave it.
            . "chown $o:$o $b $b/blue $b/green $b/vault $b/public $b/files $b/logs $b/private; "
            . "chmod 755 $b $b/blue $b/green; "
            . "chmod 750 $b/files $b/logs $b/private; ";
        if ($deployer !== null && $deployer !== '' && $deployer !== 'root') {
            $d = escapeshellarg($deployer);
            $script .= "usermod -aG $o $d; "
                . "chmod 2775 $b/public; chmod 2770 $b/vault; "
                . "find $b/vault -mindepth 1 -type f -exec chmod 660 {} +; "
                . "printf '%s\\n' $d > $b/private/deployer; chown $o:$o $b/private/deployer; chmod 640 $b/private/deployer";
        } else {
            $script .= "chmod 755 $b/public; chmod 770 $b/vault";
        }
        $ssh->sudo('sh -c ' . escapeshellarg($script));
        $this->log("Workspace $base ready (owner $owner" . ($deployer ? ", $deployer in its group" : '') . ')');
        $this->log("NOTE: the admin's log viewer reads the journal as $owner; adding $owner to systemd-journal is a box-wide change, left to you (prod/10-workspace.sh does it).");
    }

    /**
     * Install/provision-postgres.sh: the role (LOGIN, the vault's password, NOCREATEDB), the
     * database and its `_verify` scratch twin, both owned by the role, both with citext.
     *
     * Refuses a role that exists without owning the database (someone else's: its password would be
     * changed), a database or `_verify` owned by another role, and reserved or overlong names. The
     * password is in no command line: the SQL goes through a 0600 file of the login's in /dev/shm
     * (RAM), handed by root to psql on stdin, and the session turns statement logging off first so
     * the server log does not keep it either. The file is removed whatever happens.
     *
     * @param array<string, mixed> $vault
     */
    public function postgres(SshService $ssh, array $vault, string $marker): void
    {
        $db = (string) ($vault['PG_DB'] ?? '');
        $role = (string) ($vault['PG_USER'] ?? '');
        $password = (string) ($vault['PG_PASSWORD'] ?? '');
        $port = (int) ($vault['PG_PORT'] ?? 5432) ?: 5432;
        foreach (['PG_DB' => $db, 'PG_USER' => $role] as $key => $name) {
            if (!preg_match('/^[a-z_][a-z0-9_]{0,55}$/', $name) || $name === 'postgres' || str_starts_with($name, 'pg_')) {
                throw new DeployException("The key vault's $key '$name' is not a plain lowercase name of ours (reserved, over 56 characters or odd).", 'postgres');
            }
        }
        if ($password === '') {
            throw new DeployException('The key vault has no PG_PASSWORD.', 'postgres');
        }
        $ssh->addSecret($password);
        $verify = $db . '_verify';
        $comment = 'saaspaws:' . self::slug($marker);

        $q = fn(string $sql, string $in = 'postgres') => trim($ssh->sudo('runuser -u postgres -- psql -X -p ' . $port . ' -d ' . escapeshellarg($in) . ' -tAc ' . escapeshellarg($sql), true, 30)->output);
        $owner = fn(string $name) => $q("SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = '$name'");
        $note = fn(string $name) => $q("SELECT coalesce(shobj_description(oid, 'pg_database'), '') FROM pg_database WHERE datname = '$name'");
        $hasRole = $q("SELECT 1 FROM pg_roles WHERE rolname = '$role'") === '1';
        foreach ([$db, $verify] as $name) {
            $by = $owner($name);
            if ($by === '') {
                continue;
            }
            if ($by !== $role) {
                throw new DeployException("Database $name belongs to '$by', not $role: not touching it.", 'postgres');
            }
            // Ours only when an earlier Set up marked it, or it is empty: otherwise a vault copied
            // from another application would run our migrations inside that application's data.
            $tables = (int) $q("SELECT count(*) FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog', 'information_schema')", $name);
            if ($note($name) !== $comment && $tables > 0) {
                throw new DeployException("Database $name exists with $tables tables and is not marked as $marker's: not touching it.", 'postgres');
            }
        }
        if ($hasRole) {
            // An existing role is NEVER altered: if it is someone else's, changing its password
            // takes that application down. It is ours only if the vault's password logs in.
            $this->assertLogin($ssh, $role, $password, $port, $db, false);
        }

        // The role's password goes in as a SCRAM verifier made here: the plain text reaches neither
        // the box's PostgreSQL log (whatever its log settings) nor pg_stat_statements.
        $verifier = self::scram($password);
        $ssh->addSecret($verifier);
        $sql = "SET log_statement = 'none';\nSET log_min_duration_statement = -1;\nSET log_min_error_statement = 'panic';\n"
            . "\\set ON_ERROR_STOP on\n"
            . "SELECT format('CREATE ROLE %I LOGIN NOCREATEDB PASSWORD %L', '$role', '$verifier') WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '$role') \\gexec\n"
            . "SELECT format('CREATE DATABASE %I OWNER %I', '$db', '$role') WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = '$db') \\gexec\n"
            . "SELECT format('CREATE DATABASE %I OWNER %I', '$verify', '$role') WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = '$verify') \\gexec\n"
            . "COMMENT ON DATABASE \"$db\" IS '$comment';\nCOMMENT ON DATABASE \"$verify\" IS '$comment';\n"
            . "\\connect \"$db\"\nCREATE EXTENSION IF NOT EXISTS citext;\n"
            . "\\connect \"$verify\"\nCREATE EXTENSION IF NOT EXISTS citext;\n";
        $dir = $ssh->test('test -d /dev/shm -a -w /dev/shm') ? '/dev/shm' : '/tmp';
        $file = $dir . '/saaspaws-pg-' . bin2hex(random_bytes(6)) . '.sql';
        try {
            $ssh->writeFile($file, $sql, 0600);
            $ssh->sudo('sh -c ' . escapeshellarg('runuser -u postgres -- psql -X -q -p ' . $port . ' -d postgres -f - < ' . escapeshellarg($file)), timeout: 120);
        } finally {
            $ssh->exec('rm -f ' . escapeshellarg($file), false, 15);
        }
        $this->assertLogin($ssh, $role, $password, $port, $db, true);
        $this->log("PostgreSQL: role $role, databases $db and $verify (citext), marked $comment; the vault's password logs in");
    }

    /**
     * Logs in as $role over TCP with the vault's password, the way the app will. The password goes
     * through a 0600 PGPASSFILE in RAM, never through argv or the environment of a command line.
     */
    private function assertLogin(SshService $ssh, string $role, string $password, int $port, string $db, bool $created): void
    {
        $line = '127.0.0.1:' . $port . ':*:' . $role . ':' . str_replace([':', '\\'], ['\\:', '\\\\'], $password) . "\n";
        $dir = $ssh->test('test -d /dev/shm -a -w /dev/shm') ? '/dev/shm' : '/tmp';
        $file = $dir . '/saaspaws-pgpass-' . bin2hex(random_bytes(6));
        try {
            $ssh->writeFile($file, $line, 0600);
            $ok = $ssh->exec('PGPASSFILE=' . escapeshellarg($file) . ' psql -X -w -h 127.0.0.1 -p ' . $port . ' -U ' . escapeshellarg($role) . ' -d ' . escapeshellarg($created ? $db : 'postgres') . ' -tAc ' . escapeshellarg('SELECT 1'), false, 30);
        } finally {
            $ssh->exec('rm -f ' . escapeshellarg($file), false, 15);
        }
        if (!$ok->ok() || trim($ok->output) !== '1') {
            throw new DeployException($created
                ? "Role $role and database $db are in place, but logging in with the vault's password over 127.0.0.1 fails (pg_hba.conf?): " . trim($ok->text())
                : "Role $role exists and the key vault's password does not log in as it: it is not ours, or its password differs. Not changing it.", 'postgres');
        }
    }

    /** The application's name as a marker: lowercase letters, digits, dot, dash, underscore. */
    public static function slug(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9_.-]+/', '-', strtolower($name)), '-') ?: 'app';
    }

    /**
     * The shared headers snippet the release's Fluffy would write must be the one the box already
     * has: `php fluffy nginx` writes it and reloads before anything can put the old one back, so a
     * different one would reach every Fluffy site on the box for those seconds.
     */
    public function assertSnippet(SshService $ssh, string $release): void
    {
        $box = '/etc/nginx/snippets/fluffy-security-headers.conf';
        if (!$ssh->test('test -f ' . escapeshellarg($box))) {
            return;
        }
        $ours = $release . '/vendor/fluffy-space/fluffy/bin/setup/nginx-security-headers.conf';
        $sum = fn(string $path) => trim((string) strtok($ssh->exec('sha256sum ' . escapeshellarg($path), false, 15)->output, ' '));
        if ($sum($box) !== $sum($ours)) {
            throw new DeployException("$box differs from the one this release's Fluffy writes: every Fluffy site on the box would get the new headers. Align them first.", 'edge');
        }
    }

    /**
     * A Let's Encrypt certificate for $domain, then the vhost regenerated onto it (edge()).
     *
     * Webroot mode into the vhost's own `root` (the BUILT public copy: a token anywhere else is a
     * 404, every renewal). Before Let's Encrypt is asked, the challenge path is proven with our own
     * token fetched by the domain name from the box, so a broken path or DNS pointing elsewhere
     * costs no failed validation (those are rate-limited). The reload on renewal is attached to
     * THIS certificate (`--deploy-hook`, stored in its renewal file), not installed box-wide.
     * An existing certificate is kept (`--keep-until-expiring`); the vhost is still regenerated.
     */
    public function certificate(SshService $ssh, string $release, string $base, string $domain, array $upstreamConfs, int $slotPort, string $email): void
    {
        $vhost = "/etc/nginx/sites-available/$domain";
        $root = trim($ssh->exec('sed -n ' . escapeshellarg('s/^[[:space:]]*root[[:space:]]\+\([^;]*\);.*/\1/p') . ' ' . escapeshellarg($vhost) . ' | head -n 1', false, 15)->output);
        if ($root === '' || !str_starts_with($root, '/')) {
            throw new DeployException("No `root` in $vhost: run Set up first.", 'certificate');
        }
        $binary = $ssh->test('test -x ' . self::OPENRESTY) ? self::OPENRESTY : 'nginx';
        $service = $binary === self::OPENRESTY ? 'openresty' : 'nginx';

        // 1. Prove the challenge path ourselves: our token, served from the webroot, fetched by name.
        $token = 'saaspaws-' . bin2hex(random_bytes(12));
        $dir = rtrim($root, '/') . '/.well-known/acme-challenge';
        $ssh->sudo('mkdir -p ' . escapeshellarg($dir));
        $ssh->sudo('sh -c ' . escapeshellarg('printf %s ' . escapeshellarg($token) . ' > ' . escapeshellarg("$dir/$token")));
        try {
            $got = trim($ssh->exec('curl -s --max-time 10 ' . escapeshellarg("http://$domain/.well-known/acme-challenge/$token"), false, 20)->output);
        } finally {
            $ssh->sudo('rm -f ' . escapeshellarg("$dir/$token"), false, 15);
        }
        if ($got !== $token) {
            throw new DeployException("http://$domain/.well-known/acme-challenge/ does not serve files from $root (DNS pointing elsewhere, or the vhost): Let's Encrypt was NOT asked.", 'certificate');
        }
        $this->log("The challenge path works: http://$domain/.well-known/acme-challenge/ serves $root");

        // 2. Issue (or keep) the certificate.
        $live = "/etc/letsencrypt/live/$domain";
        if ($ssh->sudo('test -d ' . escapeshellarg($live), false, 15)->ok()) {
            $this->log("A certificate for $domain is already there: kept");
        } else {
            $hasAccount = trim($ssh->sudo('sh -c ' . escapeshellarg('ls -A /etc/letsencrypt/accounts/*/directory/ 2>/dev/null | head -n 1'), false, 15)->output) !== '';
            if (!$hasAccount && $email === '') {
                throw new DeployException('The box has no Let\'s Encrypt account yet and the key vault names no admin email to register one with.', 'certificate');
            }
            $command = 'certbot certonly --non-interactive --agree-tos --keep-until-expiring --webroot'
                . ' -w ' . escapeshellarg($root) . ' -d ' . escapeshellarg($domain) . ' --cert-name ' . escapeshellarg($domain)
                . ' --deploy-hook ' . escapeshellarg("systemctl reload $service")
                . ($hasAccount ? '' : ' -m ' . escapeshellarg($email) . ' --no-eff-email');
            $issued = $ssh->sudo($command, false, 300);
            if (!$issued->ok() || !$ssh->sudo('test -d ' . escapeshellarg($live), false, 15)->ok()) {
                throw new DeployException("certbot did not issue a certificate for $domain: " . trim($issued->text()), 'certificate');
            }
            $this->log("Certificate issued for $domain (renewals reload $service)");
        }

        // 3. The vhost onto it, with the same backup, test and rollback as Set up.
        $this->edge($ssh, $release, $base, $domain, $upstreamConfs, $slotPort);

        // 4. Through the public name, certificate checked this time.
        $code = trim($ssh->exec("curl -s -o /dev/null -w '%{http_code}' --max-time 10 " . escapeshellarg("https://$domain/health"), false, 20)->output);
        if ($code !== '200') {
            throw new DeployException("https://$domain/health answers $code with the certificate checked.", 'certificate');
        }
        $this->log("https://$domain/ is served with a valid certificate");
    }

    /** Our vhost must not become a fallback: checked again with the RELEASE's template, after the build. */
    public function assertFallbacks(SshService $ssh, string $release, string $domain): void
    {
        $binary = $ssh->test('test -x ' . self::OPENRESTY) ? self::OPENRESTY : 'nginx';
        $template = $ssh->exec('cat ' . escapeshellarg($release . '/vendor/fluffy-space/fluffy/bin/setup/nginx.conf'), false, 15);
        $dump = ServerInspector::loadedConfig($ssh, $binary);
        if (!$template->ok() || !$dump->ok()) {
            throw new DeployException("Could not read the release's vhost template or the loaded config to check the fallback servers.", 'edge');
        }
        foreach (ServerInspector::fallbacks($dump->output, "/etc/nginx/sites-enabled/$domain", ServerInspector::listensIn($template->output)) as $socket => [$safe, $detail]) {
            if (!$safe) {
                throw new DeployException("On $socket: $detail.", 'edge');
            }
        }
    }

    /** A SCRAM-SHA-256 verifier for $password (what PostgreSQL stores), so the plain text is never sent. */
    public static function scram(string $password, ?string $salt = null, int $iterations = 4096): string
    {
        $salt ??= random_bytes(16);
        $salted = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
        $stored = hash('sha256', hash_hmac('sha256', 'Client Key', $salted, true), true);
        $server = hash_hmac('sha256', 'Server Key', $salted, true);
        return 'SCRAM-SHA-256$' . $iterations . ':' . base64_encode($salt) . '$' . base64_encode($stored) . ':' . base64_encode($server);
    }

    /**
     * The Redis db index the release was built with (configs/app.php `redis.dbIndex`). Fluffy's keys
     * carry no per-application prefix, so two applications on one index would read and overwrite
     * each other's cache, rate limits and settings. Claimed with an owner key: an empty index is
     * taken, one that already carries our key is ours (a rerun), anything else is refused.
     */
    public function redis(SshService $ssh, string $release, string $marker): void
    {
        $php = '$c = require "configs/app.php"; echo json_encode($c["redis"] ?? null);';
        $config = json_decode($ssh->read('cd ' . escapeshellarg($release) . ' && php -r ' . escapeshellarg($php) . ' 2>/dev/null | tail -n 1'), true);
        if (!is_array($config) || !isset($config['dbIndex'])) {
            throw new DeployException("Could not read redis.dbIndex from $release/configs/app.php.", 'redis');
        }
        $index = (int) $config['dbIndex'];
        $port = (int) ($config['port'] ?? 6379);
        $auth = (string) ($config['auth'] ?? '');
        $owner = 'saaspaws:owner';
        $marker = self::slug($marker);
        $cli = function (string $args) use ($ssh, $port, $index, $auth) {
            $command = 'redis-cli -p ' . $port . ' -n ' . $index . ' ' . $args;
            if ($auth === '') {
                return $ssh->exec($command, false, 20);
            }
            // The password through a 0600 file read inside the shell, never on a command line.
            $ssh->addSecret($auth);
            $file = '/dev/shm/saaspaws-redis-' . bin2hex(random_bytes(6));
            try {
                $ssh->writeFile($file, $auth, 0600);
                return $ssh->exec('REDISCLI_AUTH="$(cat ' . escapeshellarg($file) . ')" ' . $command, false, 20);
            } finally {
                $ssh->exec('rm -f ' . escapeshellarg($file), false, 15);
            }
        };
        $size = $cli('DBSIZE');
        if (!$size->ok() || !preg_match('/^\d+$/', trim($size->output))) {
            throw new DeployException("Redis db $index on port $port did not answer DBSIZE: " . trim($size->text()), 'redis');
        }
        $keys = (int) trim($size->output);
        $current = trim($cli('GET ' . $owner)->output);
        if ($current === $marker) {
            $this->log("Redis db $index is already this application's ($keys keys)");
            return;
        }
        if ($keys > 0) {
            throw new DeployException("Redis db $index holds $keys keys and is not marked as $marker's: another application uses it. Pick a free index in configs/build.php.", 'redis');
        }
        $set = $cli('SET ' . $owner . ' ' . escapeshellarg($marker) . ' NX');
        if (!$set->ok() || trim($set->output) !== 'OK') {
            throw new DeployException("Could not claim Redis db $index: " . trim($set->text()), 'redis');
        }
        $this->log("Redis db $index was empty and is now claimed for $marker");
    }

    /**
     * What the release itself will tell Fluffy's builders: unit names, ports and the upstream in its
     * configs/server.php. The builders run as root with these names, so they must be the ones the
     * deploy admin has (DeploymentService::setup compares them).
     *
     * @return array{instances: array<string, array{service: string, port: int}>, upstream: ?string, user: ?string}
     */
    public function releaseConfig(SshService $ssh, string $release): array
    {
        $php = 'require "vendor/autoload.php"; $c = require "configs/server.php"; '
            . 'echo json_encode(["instances" => $c["instances"] ?? [], "upstream" => $c["upstream"] ?? null, "user" => $c["service_user"] ?? null]);';
        $json = $ssh->read('cd ' . escapeshellarg($release) . ' && php -r ' . escapeshellarg($php) . ' 2>/dev/null | tail -n 1');
        $config = json_decode($json, true);
        if (!is_array($config)) {
            throw new DeployException("Could not read configs/server.php in $release.", 'units');
        }
        $instances = [];
        foreach ((array) ($config['instances'] ?? []) as $color => $instance) {
            $instances[(string) $color] = ['service' => (string) ($instance['service'] ?? ''), 'port' => (int) ($instance['port'] ?? 0)];
        }
        return [
            'instances' => $instances,
            'upstream' => isset($config['upstream']) ? (string) $config['upstream'] : null,
            'user' => isset($config['user']) ? (string) $config['user'] : null,
        ];
    }

    /**
     * `php fluffy service` from the release: both colours' units. Refuses first when a unit file of
     * that name exists and runs from outside our base folder (another application's unit). Then
     * reloads systemd itself (the builder's own `sudo systemctl daemon-reload` needs a sudo binary).
     */
    public function units(SshService $ssh, string $release, string $base, array $unitNames): void
    {
        foreach ($unitNames as $name) {
            $file = "/etc/systemd/system/$name.service";
            if ($ssh->test('test -f ' . escapeshellarg($file))) {
                $dir = rtrim(trim($ssh->exec('sed -n ' . escapeshellarg('s/^WorkingDirectory=//p') . ' ' . escapeshellarg($file) . ' | head -n 1', false, 15)->output), '/');
                $ours = [rtrim($base, '/') . '/blue', rtrim($base, '/') . '/green'];
                if (!in_array($dir, $ours, true)) {
                    throw new DeployException("$file exists and runs from '" . ($dir === '' ? 'nowhere it says' : $dir) . "', not from $base/<colour>: another application's unit.", 'units');
                }
            }
        }
        $ssh->sudo('sh -c ' . escapeshellarg('cd ' . escapeshellarg($release) . ' && php fluffy service'), timeout: 120);
        $ssh->sudo('systemctl daemon-reload', timeout: 60);
        foreach ($unitNames as $name) {
            if (!$ssh->test('test -f ' . escapeshellarg("/etc/systemd/system/$name.service"))) {
                throw new DeployException("`php fluffy service` did not write /etc/systemd/system/$name.service.", 'units');
            }
        }
        $this->log('Units written: ' . implode(', ', $unitNames));
    }

    /** Start on boot. The other slot's units stay disabled: a reboot brings back the live release. */
    public function enable(SshService $ssh, array $unitNames): void
    {
        foreach ($unitNames as $name) {
            $ssh->sudo('systemctl enable ' . escapeshellarg($name), timeout: 60);
        }
        $this->log('Enabled at boot: ' . implode(', ', $unitNames));
    }

    /**
     * `php fluffy nginx <domain>` from the release (upstream file, shared headers snippet, vhost on
     * the self-signed pair until a certificate exists), then OUR test of the whole config: the
     * builder dies on a failed test with exit status 0 and leaves its files in place, and a broken
     * file there would take every site on the box down at the next reload anyone does.
     *
     * Every file the builder may write is copied aside first (into the app's private/ folder, out of
     * nginx's reach); on a failed test the ones that were there are put back and the new ones
     * removed, and the config is tested again. The shared headers snippet is put back even on
     * success when the box had one.
     *
     * Then the vhost is proven to route to THIS app: the page title of `/` through the web server
     * (127.0.0.1:443, certificate not checked: it is the self-signed one until "Issue certificate")
     * must equal the slot's own on its loopback port. A /health that answers 200 proves nothing:
     * every Fluffy app has one, and an unloaded vhost falls through to another site's.
     *
     * @param string[] $upstreamConfs the upstream files' paths (DeployUpstreamEntity::confPath())
     */
    public function edge(SshService $ssh, string $release, string $base, string $domain, array $upstreamConfs, int $slotPort): void
    {
        $binary = $ssh->test('test -x ' . self::OPENRESTY) ? self::OPENRESTY : 'nginx';
        $service = $binary === self::OPENRESTY ? 'openresty' : 'nginx';
        $snippet = '/etc/nginx/snippets/fluffy-security-headers.conf';
        $paths = array_merge(["/etc/nginx/sites-available/$domain", "/etc/nginx/sites-enabled/$domain", $snippet], $upstreamConfs);
        if (!$ssh->sudo($binary . ' -t', false, 60)->ok()) {
            throw new DeployException("The web server's config fails its test BEFORE anything was written: not touching it.", 'edge');
        }

        // Backups OUTSIDE /etc/nginx: a copy beside the original would be loaded by the include
        // globs (a second vhost, a duplicate upstream) and found again by the next Check.
        $stash = rtrim($base, '/') . '/private/setup-backup-' . date('YmdHis');
        $ssh->sudo('mkdir -p ' . escapeshellarg($stash));
        $existed = [];
        foreach ($paths as $i => $path) {
            if ($ssh->test('test -e ' . escapeshellarg($path) . ' -o -L ' . escapeshellarg($path))) {
                $ssh->sudo('cp -a ' . escapeshellarg($path) . ' ' . escapeshellarg("$stash/$i"));
                $existed[$i] = $path;
            }
        }
        $putBack = function (array $which) use ($ssh, $paths, $existed, $stash) {
            foreach ($paths as $i => $path) {
                if (!in_array($path, $which, true)) {
                    continue;
                }
                if (isset($existed[$i])) {
                    $ssh->sudo('cp -a ' . escapeshellarg("$stash/$i") . ' ' . escapeshellarg($path), false, 15);
                } else {
                    $ssh->sudo('rm -f ' . escapeshellarg($path), false, 15);
                }
            }
        };

        $ssh->sudo('sh -c ' . escapeshellarg('cd ' . escapeshellarg($release) . ' && php fluffy nginx ' . escapeshellarg($domain)), false, 120);
        // The shared snippet is every Fluffy site's on this box: if it was there, the box's own copy
        // stays (our vhost only needs the file to exist), whatever this release's Fluffy carries.
        if (in_array($snippet, $existed, true)) {
            $putBack([$snippet]);
        }
        $test = $ssh->sudo($binary . ' -t', false, 60);
        if (!$test->ok()) {
            $this->log('The config test FAILS with our files: putting back what was there and removing what was new');
            $putBack($paths);
            $back = $ssh->sudo($binary . ' -t', false, 60);
            if ($back->ok()) {
                $ssh->sudo("systemctl reload $service", false, 60);
            }
            throw new DeployException(
                'Our vhost broke the web server config (' . trim($test->text()) . '). '
                    . ($back->ok() ? 'Put back; the config passes as before.' : "PUT BACK, BUT THE CONFIG STILL FAILS: check the box by hand now (copies in $stash)."),
                'edge'
            );
        }
        $ssh->sudo("systemctl reload $service", false, 60);
        $this->log("Web server: $domain configured and $service reloaded (copies of what was there: $stash)");

        $title = fn(string $command) => preg_match('/<title>(.*?)<\/title>/is', $ssh->exec($command, false, 20)->output, $m) ? trim($m[1]) : '';
        $direct = $title('curl -s --max-time 10 -H ' . escapeshellarg("Host: $domain") . ' ' . escapeshellarg("http://127.0.0.1:$slotPort/"));
        $edge = '';
        for ($i = 0; $i < 15 && ($edge === '' || $edge !== $direct); $i++) {
            $edge = $title('curl -sk --max-time 10 --resolve ' . escapeshellarg("$domain:443:127.0.0.1") . ' ' . escapeshellarg("https://$domain/"));
            if ($edge === '' || $edge !== $direct) {
                $ssh->exec('sleep 1', false, 5);
            }
        }
        if ($direct === '' || $edge !== $direct) {
            throw new DeployException(
                "The vhost does not route to this app: https://$domain/ through the web server shows '$edge', the slot itself '$direct'.",
                'edge'
            );
        }
        $this->log("https://$domain/ through the web server is this app ('$edge')");
    }

    private function log(string $line): void
    {
        if ($this->log !== null) {
            ($this->log)($line);
        }
    }
}
