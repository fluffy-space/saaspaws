<?php

namespace SaasPaws\Backup\Backups\Services;

use Swoole\Coroutine\System;

/**
 * The handful of SFTP verbs a Hetzner Storage Box actually offers, wrapped so BackupService can
 * ignore the mechanics.
 *
 * A Storage Box is NOT a shell host. There is no remote shell, so `find -mtime +7 -delete` — the
 * obvious way to do retention — cannot work, and neither can `mkdir -p` or `test -f`. Everything
 * here is built from sftp batch commands: ls, put, rename, rm, mkdir. That constraint is the
 * entire reason this class exists rather than a couple of inline exec calls.
 *
 * Paths are RELATIVE to the session's working directory, which for a sub-account is its own home.
 * Writing them absolute (a leading "/") targets the server root instead, and the failure is
 * confusing: the mkdirs appear to succeed somewhere else while the upload fails on a directory
 * that "was just created".
 */
class SftpTransport
{
    private string $keyFile;
    private ?string $knownHostsFile = null;

    public function __construct(
        private string $host,
        private string $user,
        private int $port,
        string $privateKey,
        string $knownHosts,
        private string $workDir,
        private int $timeout,
        private string $artifactPrefix,
    ) {
        // Host and user reach the sftp command and ssh-keyscan; BackupService validates them from
        // settings already, this is the backstop for any other caller.
        if (preg_match('/^[A-Za-z0-9.-]+$/', $host) !== 1 || str_starts_with($host, '-')
            || preg_match('/^[A-Za-z0-9._-]+$/', $user) !== 1 || str_starts_with($user, '-')) {
            throw new BackupException('invalid Storage Box host or user');
        }
        // 0600: OpenSSH refuses a key readable by anyone but its owner. It does so only AFTER the
        // server accepts the public key, then silently falls back to password auth — so the
        // symptom is a bare "Permission denied" that looks like an authorization problem.
        $this->keyFile = $workDir . '/id_backup';
        file_put_contents($this->keyFile, rtrim($privateKey, "\n") . "\n");
        chmod($this->keyFile, 0600);

        $knownHosts = trim($knownHosts);
        if ($knownHosts !== '') {
            $this->knownHostsFile = $workDir . '/known_hosts';
            file_put_contents($this->knownHostsFile, rtrim($knownHosts, "\n") . "\n");
            chmod($this->knownHostsFile, 0644);
        }
    }

    /** mkdir -p, in a protocol without -p: attempt each level, ignore "already exists". */
    public function mkdirP(string $path): void
    {
        $acc = '';
        $lines = [];
        foreach (explode('/', trim($path, '/')) as $part) {
            if ($part === '') {
                continue;
            }
            $acc = $acc === '' ? $part : $acc . '/' . $part;
            $lines[] = '-mkdir "' . $acc . '"';
        }
        if ($lines !== []) {
            $this->batch($lines);
        }
    }

    /**
     * Upload atomically: write .part, then rename over the final name. A connection dropped
     * mid-transfer must not leave something that LOOKS like a backup — retention counts files, so
     * a truncated artifact would push a good one out of the window.
     */
    public function put(string $localFile, string $remoteDir, string $remoteName): void
    {
        $result = $this->batch([
            'put "' . $localFile . '" "' . $remoteDir . '/' . $remoteName . '.part"',
            'rename "' . $remoteDir . '/' . $remoteName . '.part" "' . $remoteDir . '/' . $remoteName . '"',
        ]);
        if (($result['code'] ?? 1) !== 0) {
            throw new BackupException(
                'upload to ' . $remoteDir . '/' . $remoteName . ' failed: '
                    . trim((string) ($result['output'] ?? ''))
            );
        }
    }

    /**
     * Pull an artifact back down. The mirror of put(), and the half without which none of this is
     * a backup system — writing is the easy direction.
     */
    public function get(string $remotePath, string $localFile): void
    {
        $result = $this->batch(['get "' . $remotePath . '" "' . $localFile . '"']);
        if (($result['code'] ?? 1) !== 0 || !is_file($localFile) || filesize($localFile) === 0) {
            throw new BackupException(
                'download of ' . $remotePath . ' failed: ' . trim((string) ($result['output'] ?? ''))
            );
        }
    }

    /**
     * Names only, oldest first. Artifact names are ISO-8601 UTC, so lexical sort IS chronological.
     *
     * Only OUR artifacts: "<prefix>-..." ending .tar, .tar.age or .pgc. The systemd pg-dump tier
     * (prod/backup/pg-dump.sh) writes daily/weekly/monthly folders under the same base, and those
     * must neither count as artifacts nor be pruned.
     */
    public function listNames(string $remoteDir): array
    {
        $result = $this->batch(['ls -1 "' . $remoteDir . '"']);
        $names = [];
        foreach (explode("\n", (string) ($result['output'] ?? '')) as $line) {
            $line = trim($line);
            // Drop sftp's echo of the command it ran BEFORE taking basenames — the other order
            // rewrites "sftp> ls -1 \"a/b\"" into "b\"" and leaves it looking like a real file.
            if ($line === '' || str_starts_with($line, 'sftp>') || str_ends_with($line, '.part')) {
                continue;
            }
            // A directory no run has created yet answers "Can't ls: ... not found", which would
            // otherwise be listed as an artifact.
            if (str_starts_with($line, "Can't ls")) {
                continue;
            }
            $line = basename($line);
            if ($line === '' || !str_starts_with($line, $this->artifactPrefix . '-')) {
                continue;
            }
            if (str_ends_with($line, '.tar') || str_ends_with($line, '.tar.age') || str_ends_with($line, '.pgc')) {
                $names[] = $line;
            }
        }
        sort($names);
        return $names;
    }

    public function size(string $remotePath): ?int
    {
        $result = $this->batch(['ls -l "' . $remotePath . '"']);
        foreach (explode("\n", (string) ($result['output'] ?? '')) as $line) {
            if (preg_match('/^[-d]\S*\s+\S+\s+\S+\s+\S+\s+(\d+)\s/', trim($line), $m) === 1) {
                return (int) $m[1];
            }
        }
        return null;
    }

    /** Delete one file. Retention that is not count-based (per-partition) needs this. */
    public function remove(string $remotePath): void
    {
        $this->batch(['rm "' . $remotePath . '"']);
    }

    /**
     * Keep the newest $keep files; delete the rest. Counting rather than dating is deliberate —
     * remote mtime is the upload time and can drift, while the count is exactly what the retention
     * setting promises.
     */
    public function prune(string $remoteDir, int $keep): void
    {
        $names = $this->listNames($remoteDir);
        $excess = count($names) - $keep;
        if ($excess <= 0) {
            return;
        }
        $lines = [];
        foreach (array_slice($names, 0, $excess) as $name) {
            $lines[] = 'rm "' . $remoteDir . '/' . $name . '"';
        }
        $this->batch($lines);
        echo '[Backup] pruned ' . $excess . ' from ' . $remoteDir . ' (keeping ' . $keep . ')' . PHP_EOL;
    }

    /** Learn the host key on first use, so it can be pinned in settings from then on. */
    public function scanHostKey(): string
    {
        $result = System::exec(
            'timeout -k 5 30 ssh-keyscan -p ' . escapeshellarg((string) $this->port)
                . ' -H ' . escapeshellarg($this->host) . ' 2>/dev/null'
        );
        return trim((string) ($result['output'] ?? ''));
    }

    private function batch(array $lines): array
    {
        $script = $this->workDir . '/sftp-batch';
        file_put_contents($script, implode("\n", $lines) . "\n");

        $cmd = 'sftp -b ' . escapeshellarg($script)
            . ' -P ' . escapeshellarg((string) $this->port)
            . ' -i ' . escapeshellarg($this->keyFile)
            . ' -o BatchMode=yes -o ConnectTimeout=30';

        if ($this->knownHostsFile !== null) {
            // Pinned: without this an attacker able to answer for that hostname receives every
            // backup, and from here it is indistinguishable from a successful upload.
            $cmd .= ' -o StrictHostKeyChecking=yes -o UserKnownHostsFile=' . escapeshellarg($this->knownHostsFile);
        } else {
            $cmd .= ' -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/dev/null';
        }
        $cmd .= ' ' . escapeshellarg($this->user . '@' . $this->host) . ' 2>&1';

        // Same niceness as the dump: an upload saturating the link is felt on the redirect path,
        // and a backup arriving a minute later costs nothing.
        $bounded = 'nice -n 10 ionice -c 3 timeout -k 5 ' . $this->timeout . ' bash -lc ' . escapeshellarg($cmd);
        return System::exec($bounded) ?: ['code' => 1, 'output' => 'exec failed'];
    }
}
