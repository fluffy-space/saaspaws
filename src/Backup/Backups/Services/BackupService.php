<?php

namespace SaasPaws\Backup\Backups\Services;

use SaasPaws\Backup\Backups\Data\Entities\BackupRun;
use SaasPaws\Backup\Backups\Data\Entities\BackupRunMap;
use SaasPaws\Backup\Backups\Data\Repositories\BackupRunRepository;
use Fluffy\Domain\Configuration\Config;
use Fluffy\Domain\CronTab\CronTab;
use Fluffy\Services\Settings\SettingsService;
use Fluffy\Services\UtilsService;
use Swoole\Coroutine\System;
use Throwable;

/**
 * PostgreSQL backup: dump -> verify -> encrypt -> upload -> verify -> prune -> record.
 * Copied from Urlicer (design: its docs/backup-plan.md §v2). Everything is driven from the `backup`
 * settings group. Names come from config: the database (`postgresql.dbname`) prefixes the artifacts,
 * is the default remote base and, with `_verify`, the default scratch database.
 *
 * WHY THIS SHELLS OUT INSTEAD OF USING SshService
 * SshService transfers bytes by running `base64 -d > path` as a remote COMMAND — a workaround for
 * ssh2_scp_send writing zero bytes. A Hetzner Storage Box has no shell at all; it answers only
 * SFTP/rsync/Borg. So the deploy plumbing cannot reach it and we drive `sftp` instead, through
 * Swoole\Coroutine\System::exec, which yields the coroutine rather than blocking a worker.
 *
 * WHY A STORAGE BOX IS NOT A SHELL HOST, PRACTICALLY
 * No `find -delete` for retention, no `mkdir -p`, no `test -f`. Everything below is built from the
 * handful of sftp batch verbs that do exist: ls, put, rename, rm, mkdir.
 *
 * NOTHING COUNTS AS A BACKUP UNTIL IT IS VERIFIED. The dump must parse under `pg_restore --list`
 * and contain table data, and the uploaded byte count must match what was sent, before the run is
 * marked successful or anything is pruned. Without those gates a truncated dump uploads happily,
 * reports success, and retention rotates the last good copy out from under you.
 */
class BackupService
{
    /**
     * 03:40 daily: after the nightly maintenance jobs, well before any traffic. Apps that share a
     * box need different times (two pg_dumps -Z9 at once on a small VPS compete for every core).
     */
    private const DEFAULT_SCHEDULE = '0 40 3 * * *';

    /** Artifact names as this service writes them; anything else is refused before it reaches sftp. */
    private const ARTIFACT_NAME = '/^[A-Za-z0-9._-]+$/';

    /** Hard ceiling for a single dump, so a wedged pg_dump cannot occupy a task worker forever. */
    private const DUMP_TIMEOUT = 1800;
    private const SFTP_TIMEOUT = 900;

    /** Set for the duration of a run so the psql helpers share its .pgpass. */
    private ?string $currentWorkDir = null;

    public function __construct(
        private Config $config,
        private SettingsService $settings,
        private BackupRunRepository $runs,
    ) {
    }

    // ---------------------------------------------------------------- names (config) ---

    /** The live database's name, from config `postgresql`. */
    public function databaseName(): string
    {
        $pg = $this->config->values['postgresql'] ?? [];
        $name = (string) ($pg['database'] ?? $pg['dbname'] ?? '');
        if ($name === '') {
            throw new BackupException('config postgresql.dbname is not set');
        }
        return $name;
    }

    /** The PostgreSQL role the app connects as, from config `postgresql`. */
    private function dbUser(): string
    {
        $pg = $this->config->values['postgresql'] ?? [];
        return (string) ($pg['user'] ?? $pg['username'] ?? '');
    }

    /** Artifact name prefix ("<db>-pg-...", "<db>-files-..."): the database name. */
    public function artifactPrefix(): string
    {
        return $this->databaseName();
    }

    /** Where this instance WRITES: `backup.remoteBase`, else the database name. */
    public function remoteBase(): string
    {
        $base = trim($this->settings->getString('backup.remoteBase', ''));
        return self::assertRemoteDir($base !== '' ? $base : $this->databaseName(), 'backup.remoteBase');
    }

    /**
     * Remote directories go into sftp batch lines inside double quotes: only plain relative path
     * characters, no "..", no leading "/" (a sub-account's paths are relative to its home).
     */
    private static function assertRemoteDir(string $dir, string $key): string
    {
        if (preg_match('#^[A-Za-z0-9._/-]+$#', $dir) !== 1 || str_contains($dir, '..') || str_starts_with($dir, '/')) {
            throw new BackupException("$key \"$dir\" is not a valid remote directory (letters, digits, . _ - / only; relative; no ..)");
        }
        return $dir;
    }

    /** The Storage Box host from settings, validated: it reaches sftp and ssh-keyscan. */
    private function storageHost(): string
    {
        $host = $this->requireSetting('backup.storageHost', 'Storage Box host');
        if (preg_match('/^[A-Za-z0-9.-]+$/', $host) !== 1 || str_starts_with($host, '-')) {
            throw new BackupException('backup.storageHost "' . $host . '" is not a valid host name');
        }
        return $host;
    }

    private function storageUser(): string
    {
        $user = $this->requireSetting('backup.storageUser', 'Storage Box user');
        if (preg_match('/^[A-Za-z0-9._-]+$/', $user) !== 1 || str_starts_with($user, '-')) {
            throw new BackupException('backup.storageUser "' . $user . '" is not a valid user name');
        }
        return $user;
    }

    /**
     * Is this a PostgreSQL artifact that exists at the restore base? For the "Verify" API: a name
     * from the client goes into an sftp batch line and a local path, so it must look like one of
     * ours AND be listed remotely.
     */
    public function isRestorableArtifact(string $name): bool
    {
        if (preg_match(self::ARTIFACT_NAME, $name) !== 1) {
            return false;
        }
        $workDir = $this->makeWorkDir();
        try {
            return in_array($name, $this->transport($workDir)->listNames($this->restoreBase() . '/pg-dump'), true);
        } finally {
            System::exec('rm -rf ' . escapeshellarg($workDir));
        }
    }

    // ------------------------------------------------------------------ scheduling ---

    /**
     * Is a run due?
     *
     * A cron EXPRESSION held in settings, evaluated here against the last successful run —
     * deliberately not a plain "every N minutes" interval. An interval measured from the last
     * success drifts: with a 15-minute tick a daily backup lands up to 15 minutes later each day
     * and wanders hours off its window within a month. A schedule pins the time of day, and it
     * stays live-editable because the expression is a setting rather than a line in crontab.php.
     *
     * Reuses the framework's own parser, so the syntax is the one already in crontab.php.
     *
     * A slot missed while the box was down is not skipped: the next tick sees that the run due
     * after the last success is now in the past, and takes it once.
     */
    public function isDue(): bool
    {
        if (!$this->settings->getBool('backup.enabled', false)) {
            return false;
        }
        $last = $this->lastSuccess();
        if ($last === null) {
            return true;
        }
        $expression = trim($this->settings->getString('backup.schedule', self::DEFAULT_SCHEDULE));
        if ($expression === '') {
            $expression = self::DEFAULT_SCHEDULE;
        }
        $lastSeconds = (int) ((int) $last->CreatedOn / 1_000_000);
        try {
            return CronTab::getNextRun($expression, $lastSeconds) <= time();
        } catch (Throwable $e) {
            // A malformed expression must not silently stop backups forever — that is the failure
            // this whole feature exists to prevent. Fall back to the default and say so.
            echo '[Backup] invalid backup.schedule "' . $expression . '" — falling back to '
                . self::DEFAULT_SCHEDULE . ': ' . $e->getMessage() . PHP_EOL;
            return CronTab::getNextRun(self::DEFAULT_SCHEDULE, $lastSeconds) <= time();
        }
    }

    /** When the next run is due, for the admin page. Null when disabled or never yet run. */
    public function nextDueAt(): ?int
    {
        if (!$this->settings->getBool('backup.enabled', false)) {
            return null;
        }
        $last = $this->lastSuccess();
        if ($last === null) {
            return null;
        }
        $expression = trim($this->settings->getString('backup.schedule', self::DEFAULT_SCHEDULE));
        try {
            return CronTab::getNextRun($expression === '' ? self::DEFAULT_SCHEDULE : $expression, (int) ((int) $last->CreatedOn / 1_000_000));
        } catch (Throwable) {
            return null;
        }
    }

    public function lastSuccess(): ?BackupRun
    {
        /** @var ?BackupRun */
        return $this->runs->firstOrDefault(
            [
                [BackupRunMap::PROPERTY_Status, '=', 'success'],
                // Verify rows share this table; a successful verify is not a backup having been
                // taken, and counting one as the other would make a stale schedule look healthy.
                [BackupRunMap::PROPERTY_Kind, '=', 'backup'],
            ],
            [BackupRunMap::PROPERTY_CreatedOn => -1]
        );
    }

    /**
     * The newest backup ATTEMPT, successful or not.
     *
     * Distinct from lastSuccess() on purpose. "Last success was 3 hours ago" stays reassuringly
     * green while every run since has failed — failure and staleness are different signals, and a
     * page that only expresses staleness hides a backup that broke last night behind a stale-but-
     * acceptable timestamp.
     */
    public function lastRun(): ?BackupRun
    {
        /** @var ?BackupRun */
        return $this->runs->firstOrDefault(
            [[BackupRunMap::PROPERTY_Kind, '=', 'backup']],
            [BackupRunMap::PROPERTY_CreatedOn => -1]
        );
    }

    /** The newest successful VERIFY — "when did we last prove a backup was restorable?". */
    public function lastVerify(): ?BackupRun
    {
        /** @var ?BackupRun */
        return $this->runs->firstOrDefault(
            [
                [BackupRunMap::PROPERTY_Status, '=', 'success'],
                [BackupRunMap::PROPERTY_Kind, '=', 'verify'],
            ],
            [BackupRunMap::PROPERTY_CreatedOn => -1]
        );
    }

    /** Most recent runs first — what the admin page lists. */
    public function recent(int $limit = 25): array
    {
        $result = $this->runs->search([], [BackupRunMap::PROPERTY_CreatedOn => -1], 1, $limit);
        return $result['list'] ?? [];
    }

    // ----------------------------------------------------------------------- the run ---

    /**
     * Take one backup. Returns the run row, successful or failed — this never throws, because it
     * is called from a task worker where an exception is just a line in the log nobody reads.
     * The failure lives in the row instead, where the admin page and any absence check can see it.
     */
    public function run(string $trigger = 'cron', ?string $actor = null): BackupRun
    {
        $startedAt = UtilsService::GetMicroTime();
        $run = new BackupRun();
        $run->Status = 'running';
        $run->Trigger = $trigger . ($actor !== null ? ' (' . $actor . ')' : '');
        $run->CreatedOn = $startedAt;
        $run->CreatedBy = $actor;
        $this->runs->create($run);

        $workDir = null;
        try {
            $workDir = $this->makeWorkDir();
            $this->currentWorkDir = $workDir;
            $this->execute($run, $workDir);
            $run->Status = 'success';
        } catch (Throwable $e) {
            $run->Status = 'failed';
            // Trimmed: some of these carry a whole pg_dump transcript, and the row is read by a
            // page that has to stay legible.
            $run->Error = substr($e->getMessage(), 0, 2000);
            echo '[Backup] FAILED: ' . $e->getMessage() . PHP_EOL;
        } finally {
            // Staging holds a PLAINTEXT dump for a few seconds. Never leave one behind, including
            // when the run failed — especially then, since failures are what get retried.
            if ($workDir !== null) {
                System::exec('rm -rf ' . escapeshellarg($workDir));
            }
            $this->currentWorkDir = null;
            $run->DurationMs = (int) ((UtilsService::GetMicroTime() - $startedAt) / 1000);
            $run->UpdatedOn = UtilsService::GetMicroTime();
            $this->runs->update($run);
        }
        return $run;
    }

    // ------------------------------------------------------------------- verifying ---

    /**
     * Prove a backup is restorable: fetch it, decrypt it, restore it into a throwaway database,
     * count what came back, then drop it.
     *
     * This is the step every backup plan skips and the only one that proves the rest. Writing a
     * backup exercises the easy direction; nothing about a successful upload says the bytes can
     * become a database again. It restores into a SCRATCH database and never touches the live one,
     * so it is safe to run at any time.
     *
     * Recorded as a BackupRun with Kind='verify' — one timeline, because a column of backups with
     * no verifications beside them is precisely the state that looks healthy and is not.
     */
    public function verify(?string $artifact = null, string $trigger = 'manual', ?string $actor = null): BackupRun
    {
        $startedAt = UtilsService::GetMicroTime();
        $run = new BackupRun();
        $run->Kind = 'verify';
        $run->Status = 'running';
        $run->Trigger = $trigger . ($actor !== null ? ' (' . $actor . ')' : '');
        $run->Artifact = $artifact;
        $run->CreatedOn = $startedAt;
        $run->CreatedBy = $actor;
        $this->runs->create($run);

        $workDir = null;
        $scratch = null;
        try {
            $workDir = $this->makeWorkDir();
            $this->currentWorkDir = $workDir;
            $scratch = $this->scratchDatabase();
            $this->executeVerify($run, $workDir, $scratch);
            $run->Status = 'success';
        } catch (Throwable $e) {
            $run->Status = 'failed';
            $run->Error = substr($e->getMessage(), 0, 2000);
            echo '[Backup] VERIFY FAILED: ' . $e->getMessage() . PHP_EOL;
        } finally {
            // Empty the scratch database whatever happened. A failed verify that leaves a
            // half-restored database behind turns one problem into two, and it also leaves a full
            // copy of production data sitting on disk.
            if ($scratch !== null) {
                $this->resetScratchSchema($scratch, enforce: false);
            }
            if ($workDir !== null) {
                System::exec('rm -rf ' . escapeshellarg($workDir));
            }
            $this->currentWorkDir = null;
            $run->DurationMs = (int) ((UtilsService::GetMicroTime() - $startedAt) / 1000);
            $run->UpdatedOn = UtilsService::GetMicroTime();
            $this->runs->update($run);
        }
        return $run;
    }

    private function executeVerify(BackupRun $run, string $workDir, string $scratch): void
    {
        $fetched = $this->fetchAndUnpack($workDir, $run->Artifact);
        $run->Artifact = $fetched['artifact'];
        $run->SizeBytes = $fetched['bytes'];
        $run->Encrypted = $fetched['encrypted'];
        $dumpPath = $fetched['dumpPath'];

        // --------------------------------------------------------- restore ---
        $listed = $this->run_('pg_restore --list ' . escapeshellarg($dumpPath) . ' 2>/dev/null', 120);
        if (($listed['code'] ?? 1) !== 0) {
            throw new BackupException('the artifact is not a readable pg_dump archive');
        }
        $expectedTables = substr_count((string) ($listed['output'] ?? ''), 'TABLE DATA');

        // Reset the pre-created scratch database rather than making one. Dropping and recreating
        // a SCHEMA is something its owner can do; CREATE DATABASE needs the CREATEDB privilege,
        // which would then sit on the app role permanently — letting a compromised app spawn
        // databases until the disk filled — to serve one admin feature.
        $this->resetScratchSchema($scratch, enforce: true);
        $this->psql('CREATE EXTENSION IF NOT EXISTS citext', $scratch, 60);

        $pg = $this->config->values['postgresql'];
        $restore = $this->run_(
            'PGPASSFILE=' . escapeshellarg($this->passFilePath($workDir)) . ' pg_restore --no-owner --no-privileges'
                . ' -h ' . escapeshellarg((string) ($pg['host'] ?? '127.0.0.1'))
                . ' -p ' . escapeshellarg((string) ($pg['port'] ?? 5432))
                . ' -U ' . escapeshellarg($this->dbUser())
                . ' -d ' . escapeshellarg($scratch)
                . ' ' . escapeshellarg($dumpPath) . ' 2>&1',
            self::DUMP_TIMEOUT
        );
        // pg_restore exits non-zero on benign role/ownership complaints against a fresh database,
        // so the verdict comes from what actually landed, not from the exit code.
        $errors = substr_count((string) ($restore['output'] ?? ''), 'pg_restore: error');

        // ANALYZE first: n_live_tup comes from planner statistics, which autovacuum has not
        // gathered on a database seconds old. Without it the count reads as a near-empty restore.
        $this->psql('ANALYZE', $scratch, 300);
        $tables = (int) trim($this->psqlRead(
            "SELECT count(*) FROM pg_stat_user_tables WHERE n_live_tup > 0",
            $scratch
        ));
        $rows = (int) trim($this->psqlRead(
            "SELECT COALESCE(sum(n_live_tup), 0) FROM pg_stat_user_tables",
            $scratch
        ));
        $run->TablesWithData = $tables;

        if ($tables < 1) {
            throw new BackupException(
                'the restore produced no populated tables (' . $errors . ' pg_restore errors) — '
                    . 'this artifact would NOT get you your data back'
            );
        }
        // $run->Artifact, not a local: fetchAndUnpack() resolves which artifact was actually
        // fetched (the newest, when none was named) and records it on the run.
        echo '[Backup] verified ' . ($run->Artifact ?? 'artifact') . ': ' . $tables . ' tables, ' . $rows . ' rows'
            . ($errors > 0 ? ', ' . $errors . ' non-fatal pg_restore errors' : '')
            . ' (archive declared ' . $expectedTables . ' tables with data)' . PHP_EOL;
    }

    private function execute(BackupRun $run, string $workDir): void
    {
        $pg = $this->config->values['postgresql'];
        $database = $this->databaseName();

        $stamp = gmdate('Ymd\THis\Z');
        $dumpPath = $workDir . '/' . $database . '.pgc';

        // ---------------------------------------------------------------- 1. dump ---
        // No downtime: pg_dump takes an MVCC snapshot in a repeatable-read transaction and only
        // ACCESS SHARE locks, which conflict solely with DDL. Readers and writers are unaffected.
        // The password goes via PGPASSFILE, never argv — argv is world-readable through `ps`.
        $passFile = $this->passFilePath($workDir);

        $cmd = 'PGPASSFILE=' . escapeshellarg($passFile) . ' pg_dump -Fc -Z9'
            . ' -h ' . escapeshellarg((string) ($pg['host'] ?? '127.0.0.1'))
            . ' -p ' . escapeshellarg((string) ($pg['port'] ?? 5432))
            . ' -U ' . escapeshellarg($this->dbUser())
            . ' -d ' . escapeshellarg($database)
            . $this->excludeArgs()
            . ' -f ' . escapeshellarg($dumpPath) . ' 2>&1';

        $result = $this->run_($cmd, self::DUMP_TIMEOUT);
        if (($result['code'] ?? 1) !== 0) {
            throw new BackupException('pg_dump failed: ' . trim((string) ($result['output'] ?? '')));
        }
        if (!is_file($dumpPath) || filesize($dumpPath) === 0) {
            throw new BackupException('pg_dump produced no output');
        }
        $dumpBytes = (int) filesize($dumpPath);

        // -------------------------------------------------------------- 2. verify ---
        // Walk the archive's table of contents. A truncated or corrupt dump fails HERE rather than
        // on the day it is needed — and, critically, before retention deletes an older good copy.
        $listed = $this->run_('pg_restore --list ' . escapeshellarg($dumpPath) . ' 2>/dev/null', 120);
        if (($listed['code'] ?? 1) !== 0) {
            throw new BackupException('the dump is not readable by pg_restore — refusing to upload it');
        }
        $tables = substr_count((string) ($listed['output'] ?? ''), 'TABLE DATA');
        if ($tables < 1) {
            throw new BackupException('the dump contains no table data — refusing to upload it');
        }
        $run->TablesWithData = $tables;

        // ------------------------------------------------------------- 3. package ---
        // The dump plus a manifest describing it, tarred together. The metadata has to live INSIDE
        // the artifact, not only in the BackupRun row: the row is in the database, and the case
        // that matters is restoring precisely when that database is gone. A restore two years from
        // now should not need this repository to work out what it is holding.
        file_put_contents($workDir . '/meta.json', json_encode([
            'created' => gmdate('Y-m-d\TH:i:s\Z'),
            'host' => gethostname(),
            'database' => $database,
            'server_version' => $this->serverVersion($pg, $database, $passFile),
            'dump_bytes' => $dumpBytes,
            'tables_with_data' => $tables,
            'excluded_tables' => trim($this->settings->getString('backup.excludeTables', '')),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $bundlePath = $workDir . '/bundle.tar';
        $tar = $this->run_(
            'tar -cf ' . escapeshellarg($bundlePath)
                . ' -C ' . escapeshellarg($workDir)
                . ' ' . escapeshellarg(basename($dumpPath)) . ' meta.json 2>&1',
            300
        );
        if (($tar['code'] ?? 1) !== 0 || !is_file($bundlePath) || filesize($bundlePath) === 0) {
            throw new BackupException('packaging failed: ' . trim((string) ($tar['output'] ?? '')));
        }

        // ------------------------------------------------------------- 4. encrypt ---
        $recipient = trim($this->settings->getString('backup.ageRecipient', ''));
        $artifact = $this->artifactPrefix() . '-pg-' . $stamp . '.tar';
        $uploadPath = $bundlePath;
        if ($recipient !== '') {
            $this->assertAgeRecipient($recipient);
            $encPath = $bundlePath . '.age';
            $enc = $this->run_(
                'age -r ' . escapeshellarg($recipient)
                    . ' -o ' . escapeshellarg($encPath)
                    . ' ' . escapeshellarg($bundlePath) . ' 2>&1',
                600
            );
            if (($enc['code'] ?? 1) !== 0 || !is_file($encPath) || filesize($encPath) === 0) {
                throw new BackupException('age encryption failed: ' . trim((string) ($enc['output'] ?? '')));
            }
            $uploadPath = $encPath;
            $artifact .= '.age';
            $run->Encrypted = true;
        }
        $run->Artifact = $artifact;
        $localBytes = (int) filesize($uploadPath);
        $run->SizeBytes = $localBytes;

        // --------------------------------------------------------------- 5. upload ---
        $transport = $this->transport($workDir);
        $remoteDir = $this->remoteBase() . '/pg-dump';
        $transport->mkdirP($remoteDir);
        $transport->put($uploadPath, $remoteDir, $artifact);

        // sftp reports success on a connection cleanly closed after a SHORT write. Comparing the
        // byte count is what actually catches that.
        $remoteBytes = $transport->size($remoteDir . '/' . $artifact);
        if ($remoteBytes !== null && $remoteBytes !== $localBytes) {
            throw new BackupException("size mismatch after upload: local $localBytes != remote $remoteBytes");
        }

        // ---------------------------------------------------------------- 6. prune ---
        // Only now, with a verified artifact safely landed.
        $keep = max(1, (int) $this->settings->getInt('backup.retainCount', 7));
        $transport->prune($remoteDir, $keep);

        echo '[Backup] ' . $artifact . ' (' . $localBytes . ' bytes, ' . $tables . ' tables) uploaded' . PHP_EOL;
    }

    // ------------------------------------------------------------------- internals ---

    /**
     * Pull an artifact down, decrypt it, and unpack it to a plain .pgc — the half of the pipeline
     * that every read path needs, and the one worth having exactly once. Both verify and a real
     * recovery start here, so a fix to either benefits the other.
     *
     * @return array{artifact:string, dumpPath:string, bytes:int, encrypted:bool, meta:?array}
     */
    public function fetchAndUnpack(string $workDir, ?string $artifact = null): array
    {
        $this->currentWorkDir = $workDir;
        $identity = trim($this->settings->getString('backup.ageIdentity', ''));
        // READ from restoreBase when one is configured — that is what lets this machine verify and
        // restore another environment's artifacts while its own backups go somewhere else.
        $remoteDir = $this->restoreBase() . '/pg-dump';
        $transport = $this->transport($workDir);

        // Default to the newest — "is the latest backup good?" is the question worth answering
        // on a schedule, and the one you want in a hurry.
        if ($artifact === null || $artifact === '') {
            $names = $transport->listNames($remoteDir);
            if ($names === []) {
                throw new BackupException('there is nothing at ' . $remoteDir . ' to read');
            }
            $artifact = (string) end($names);
        } elseif (preg_match(self::ARTIFACT_NAME, $artifact) !== 1 || !in_array($artifact, $transport->listNames($remoteDir), true)) {
            throw new BackupException('"' . $artifact . '" is not an artifact at ' . $remoteDir);
        }

        $localPath = $workDir . '/' . $artifact;
        $transport->get($remoteDir . '/' . $artifact, $localPath);
        $bytes = (int) filesize($localPath);

        $encrypted = false;
        $bundlePath = $localPath;
        if (str_ends_with($artifact, '.age')) {
            if ($identity === '') {
                throw new BackupException(
                    'this artifact is encrypted and backup.ageIdentity is not set. That is the '
                        . 'intended state on a production box — run this from the deployer instead.'
                );
            }
            $encrypted = true;
            $identityFile = $workDir . '/identity.age';
            file_put_contents($identityFile, rtrim($identity, "\n") . "\n");
            chmod($identityFile, 0600);
            $bundlePath = $workDir . '/bundle.tar';
            $dec = $this->run_(
                'age -d -i ' . escapeshellarg($identityFile)
                    . ' -o ' . escapeshellarg($bundlePath)
                    . ' ' . escapeshellarg($localPath) . ' 2>&1',
                600
            );
            if (($dec['code'] ?? 1) !== 0 || !is_file($bundlePath) || filesize($bundlePath) === 0) {
                throw new BackupException('decryption failed — is backup.ageIdentity the right key?');
            }
        }

        // v2 artifacts are a tar of the dump plus meta.json; v1 wrote a bare .pgc. Handle both,
        // because the v1 artifacts already on the Storage Box are the ones you would reach for.
        $meta = null;
        $dumpPath = $bundlePath;
        if (str_contains($artifact, '.tar')) {
            $untar = $this->run_('tar -xf ' . escapeshellarg($bundlePath) . ' -C ' . escapeshellarg($workDir) . ' 2>&1', 300);
            if (($untar['code'] ?? 1) !== 0) {
                throw new BackupException('unpacking failed: ' . trim((string) ($untar['output'] ?? '')));
            }
            $found = glob($workDir . '/*.pgc');
            if (!$found) {
                throw new BackupException('no .pgc dump inside the archive');
            }
            $dumpPath = $found[0];
            if (is_file($workDir . '/meta.json')) {
                $meta = json_decode((string) file_get_contents($workDir . '/meta.json'), true) ?: null;
            }
        }

        return [
            'artifact' => $artifact,
            'dumpPath' => $dumpPath,
            'bytes' => $bytes,
            'encrypted' => $encrypted,
            'meta' => $meta,
        ];
    }

    public function makeWorkDirPublic(): string
    {
        return $this->makeWorkDir();
    }

    /**
     * Where reads come from: `backup.restoreBase` when set, otherwise the base this instance
     * writes to. Writes NEVER consult this — a machine must not be able to overwrite another
     * environment's artifacts just because it can read them.
     */
    public function restoreBase(): string
    {
        $restore = trim($this->settings->getString('backup.restoreBase', ''));
        return $restore !== '' ? self::assertRemoteDir($restore, 'backup.restoreBase') : $this->remoteBase();
    }

    /**
     * What is actually on the Storage Box, per tier — read from the RESTORE base.
     *
     * The BackupRun table records what THIS instance did; it says nothing about another
     * environment's artifacts. During a recovery the question is "what can I restore from",
     * and only the destination can answer that.
     *
     * @return array{base:string, pg:array, files:array}
     */
    public function remoteInventory(): array
    {
        $workDir = $this->makeWorkDir();
        try {
            $base = $this->restoreBase();
            $transport = $this->transport($workDir);
            return [
                'base' => $base,
                'pg' => array_reverse($transport->listNames($base . '/pg-dump')),
                'files' => array_reverse($transport->listNames($base . '/files')),
            ];
        } finally {
            System::exec('rm -rf ' . escapeshellarg($workDir));
        }
    }

    /** The configured Storage Box transport, so other tiers upload through the same proven path. */
    public function transportFor(string $workDir): SftpTransport
    {
        $this->currentWorkDir = $workDir;
        return $this->transport($workDir);
    }

    /** Shared child-process runner: niced, io-idled and timeout-bounded. */
    public function runCommand(string $cmd, int $timeoutSeconds): array
    {
        return $this->run_($cmd, $timeoutSeconds);
    }

    /** Write a secret to a 0600 file in the working directory, so it never appears in argv. */
    public function writeSecret(string $name, string $value): string
    {
        $dir = $this->currentWorkDir ?? sys_get_temp_dir();
        $path = $dir . '/' . $name;
        file_put_contents($path, $value);
        chmod($path, 0600);
        return $path;
    }

    private function transport(string $workDir): SftpTransport
    {
        return new SftpTransport(
            host: $this->storageHost(),
            user: $this->storageUser(),
            port: max(1, (int) $this->settings->getInt('backup.storagePort', 23)),
            privateKey: $this->requireSetting('backup.sshPrivateKey', 'Storage Box SSH private key'),
            knownHosts: $this->pinnedHostKey($workDir),
            workDir: $workDir,
            timeout: self::SFTP_TIMEOUT,
            artifactPrefix: $this->artifactPrefix(),
        );
    }

    /**
     * The pinned host key, learned on first use.
     *
     * Without a pin, sftp runs with accept-new against /dev/null — it trusts whatever answers,
     * every single time. Anyone who can answer for that hostname then receives every backup, and
     * from this side it is indistinguishable from a successful upload. Scanning once and storing
     * the result means a later impersonation FAILS instead of silently succeeding.
     *
     * Trust-on-first-use is weaker than a key checked out of band, but it is the difference
     * between "an attacker must be in position at the exact moment of setup" and "an attacker can
     * take over at any point forever".
     */
    private function pinnedHostKey(string $workDir): string
    {
        $pinned = trim($this->settings->getString('backup.knownHosts', ''));
        if ($pinned !== '') {
            return $pinned;
        }
        $host = $this->storageHost();
        $port = max(1, (int) $this->settings->getInt('backup.storagePort', 23));
        $scan = $this->run_(
            'ssh-keyscan -p ' . escapeshellarg((string) $port) . ' -H ' . escapeshellarg($host) . ' 2>/dev/null',
            60
        );
        $keys = trim((string) ($scan['output'] ?? ''));
        if ($keys === '') {
            echo '[Backup] could not scan a host key for ' . $host . ' — this run trusts whatever answers' . PHP_EOL;
            return '';
        }
        $this->settings->set('backup.knownHosts', $keys);
        echo '[Backup] pinned the Storage Box host key (' . substr_count($keys, "\n") . ' entries)' . PHP_EOL;
        return $keys;
    }

    private function requireSetting(string $key, string $label): string
    {
        $value = trim($this->settings->getString($key, ''));
        if ($value === '') {
            throw new BackupException("$label is not configured (setting $key)");
        }
        return $value;
    }

    /**
     * Guard against the single worst configuration mistake available here: pasting the PRIVATE key
     * where the public one belongs. That would hand every backup to anyone who reads the settings
     * table — and the backups would still appear to work, so nothing would ever surface it.
     */
    private function assertAgeRecipient(string $recipient): void
    {
        if (str_starts_with($recipient, 'AGE-SECRET-KEY-')) {
            throw new BackupException(
                'backup.ageRecipient holds a PRIVATE age key. It must be the age1... public key; '
                    . 'the private half must never be on this server.'
            );
        }
        if (!str_starts_with($recipient, 'age1')) {
            throw new BackupException('backup.ageRecipient does not look like an age public key (expected age1...)');
        }
    }

    /** `--exclude-table-data` keeps the schema and drops the rows — logs cost size, not value. */
    private function excludeArgs(): string
    {
        $raw = trim($this->settings->getString('backup.excludeTables', ''));
        if ($raw === '') {
            return '';
        }
        $args = '';
        foreach (explode(',', $raw) as $table) {
            $table = trim($table);
            if ($table === '') {
                continue;
            }
            // Quoted because every table in this schema is PascalCase, which unquoted PostgreSQL
            // folds to lowercase and then fails to match.
            $args .= ' --exclude-table-data=' . escapeshellarg('"' . $table . '"');
        }
        return $args;
    }

    /**
     * The throwaway database verify restores into, and a hard guard that it is not the live one.
     *
     * Misconfiguring this to the production database would have a verify wipe its schema and
     * restore over it — turning the feature that proves backups work into the thing that needs
     * one. Refused outright rather than trusted to a careful operator.
     */
    private function scratchDatabase(): string
    {
        // Default "<db>_verify", the database Install/provision-postgres.sh creates beside the live one.
        $scratch = trim($this->settings->getString('backup.verifyDatabase', ''));
        if ($scratch === '') {
            $scratch = $this->databaseName() . '_verify';
        }
        $pg = $this->config->values['postgresql'];
        $live = (string) ($pg['database'] ?? $pg['dbname'] ?? '');
        if ($live !== '' && strcasecmp($scratch, $live) === 0) {
            throw new BackupException(
                'backup.verifyDatabase is the LIVE database (' . $live . '). A verify empties it '
                    . 'before restoring, so this is refused. Point it at a throwaway database.'
            );
        }
        return $scratch;
    }

    /**
     * Empty the scratch database by replacing its public schema — the owner can do this without
     * CREATEDB. Called before a restore, and again afterwards so a full copy of production data
     * does not sit on disk between runs.
     */
    private function resetScratchSchema(string $scratch, bool $enforce): void
    {
        $result = $this->psqlExec('DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;', $scratch, 300);
        if (($result['code'] ?? 1) === 0 || !$enforce) {
            return;
        }
        $out = trim((string) ($result['output'] ?? ''));
        // The one failure worth naming: the database has not been created yet.
        if (str_contains($out, 'does not exist')) {
            throw new BackupException(
                'the scratch database "' . $scratch . '" does not exist. It is created by '
                    . 'Install/provision-postgres.sh, so re-running that is the supported fix. '
                    . 'By hand: sudo -u postgres createdb -O '
                    . $this->dbUser() . ' ' . $scratch
            );
        }
        throw new BackupException('could not reset the scratch database: ' . $out);
    }

    /**
     * A .pgpass in the working directory, written once and reused.
     *
     * The password goes here rather than into the command line because argv is world-readable
     * through `ps` — anyone with a shell on the box could read the database password out of a
     * running pg_dump.
     */
    private function passFilePath(string $workDir): string
    {
        $passFile = $workDir . '/pgpass';
        if (!is_file($passFile)) {
            $pg = $this->config->values['postgresql'];
            // ':' and '\\' are field syntax in .pgpass, so each value escapes them.
            $field = fn($v) => addcslashes((string) $v, ':\\');
            file_put_contents($passFile, sprintf(
                "%s:%s:*:%s:%s\n",
                $field($pg['host'] ?? '127.0.0.1'),
                $field($pg['port'] ?? 5432),
                $field($this->dbUser()),
                $field($pg['password'] ?? '')
            ));
            chmod($passFile, 0600);
        }
        return $passFile;
    }

    /** Run a statement, optionally against `postgres` for CREATE/DROP DATABASE. */
    private function psql(string $sql, string $database, int $timeout, bool $enforce = false): void
    {
        $result = $this->psqlExec($sql, $database, $timeout);
        if ($enforce && ($result['code'] ?? 1) !== 0) {
            $out = trim((string) ($result['output'] ?? ''));
            // The commonest cause by far, and invisible from the message PostgreSQL returns.
            if (str_contains($out, 'permission denied to create database')) {
                throw new BackupException(
                    'the application\'s PostgreSQL role cannot create databases, which a verify '
                        . 'needs for its scratch copy. Grant it: ALTER ROLE <user> CREATEDB;'
                );
            }
            throw new BackupException('psql failed: ' . $out);
        }
    }

    private function psqlRead(string $sql, string $database): string
    {
        $result = $this->psqlExec($sql, $database, 120, tuplesOnly: true);
        return (string) ($result['output'] ?? '');
    }

    private function psqlExec(string $sql, string $database, int $timeout, bool $tuplesOnly = false): array
    {
        $pg = $this->config->values['postgresql'];
        $workDir = $this->currentWorkDir ?? sys_get_temp_dir();
        return $this->run_(
            'PGPASSFILE=' . escapeshellarg($this->passFilePath($workDir)) . ' psql '
                . ($tuplesOnly ? '-tA ' : '')
                . '-v ON_ERROR_STOP=1'
                . ' -h ' . escapeshellarg((string) ($pg['host'] ?? '127.0.0.1'))
                . ' -p ' . escapeshellarg((string) ($pg['port'] ?? 5432))
                . ' -U ' . escapeshellarg($this->dbUser())
                . ' -d ' . escapeshellarg($database)
                . ' -c ' . escapeshellarg($sql) . ' 2>&1',
            $timeout
        );
    }

    private function serverVersion(array $pg, string $database, string $passFile): string
    {
        try {
            $row = $this->run_(
                'PGPASSFILE=' . escapeshellarg($passFile) . ' psql -tAc ' . escapeshellarg('SHOW server_version')
                    . ' -h ' . escapeshellarg((string) ($pg['host'] ?? '127.0.0.1'))
                    . ' -p ' . escapeshellarg((string) ($pg['port'] ?? 5432))
                    . ' -U ' . escapeshellarg($this->dbUser())
                    . ' -d ' . escapeshellarg($database) . ' 2>/dev/null',
                60
            );
            $out = trim((string) ($row['output'] ?? ''));
            return $out === '' ? 'unknown' : explode(' ', $out)[0];
        } catch (Throwable) {
            return 'unknown';
        }
    }

    private function makeWorkDir(): string
    {
        // Beside the app's other private state, not under the web root: this briefly holds a
        // plaintext dump of the entire database.
        $base = dirname((string) $this->config->values['publicDir']) . '/private/backup-staging';
        $dir = $base . '/' . bin2hex(\random_bytes(6));
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new BackupException('cannot create staging directory ' . $dir);
        }
        chmod($dir, 0700);
        return $dir;
    }

    /** System::exec yields the coroutine, so a long dump does not block the worker. */
    /**
     * Run a child process without letting it compete with serving traffic.
     *
     * Swoole is not blocked by any of this — System::exec yields the coroutine, and cron backups
     * run in a task worker rather than a request worker. What IS contended is the box: `pg_dump
     * -Z9` is CPU-hungry and disk-heavy, and on a 4-core VPS that is enough to be felt on
     * request handling. `nice`/`ionice` make the backup always lose that fight, which is the right
     * outcome — a backup that finishes a minute later costs nothing, a slow page costs a user.
     * (The v1 systemd units did this with Nice=10 / IOSchedulingClass=idle; the same intent.)
     *
     * `timeout` bounds a wedged child so it cannot hold a worker forever.
     */
    private function run_(string $cmd, int $timeoutSeconds): array
    {
        $bounded = 'nice -n 10 ionice -c 3 timeout -k 5 ' . $timeoutSeconds
            . ' bash -lc ' . escapeshellarg($cmd);
        $result = System::exec($bounded);
        if ($result === false) {
            return ['code' => 1, 'output' => 'exec failed'];
        }
        // 124 is coreutils' "the timeout expired" — worth naming, because the raw output of a
        // killed pg_dump is usually empty and reads as an unexplained failure.
        if (($result['code'] ?? 0) === 124) {
            $result['output'] = 'timed out after ' . $timeoutSeconds . 's: '
                . trim((string) ($result['output'] ?? ''));
        }
        return $result;
    }
}
