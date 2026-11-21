<?php

namespace SaasPaws\Backup\Backups\Services;

use SaasPaws\Backup\Backups\Data\Entities\BackupRun;
use SaasPaws\Backup\Backups\Data\Entities\BackupRunMap;
use SaasPaws\Backup\Backups\Data\Repositories\BackupRunRepository;
use Fluffy\Domain\Configuration\Config;
use Fluffy\Services\Settings\SettingsService;
use Fluffy\Services\UtilsService;
use Swoole\Coroutine\System;
use Throwable;

/**
 * Back up the uploaded files: the directories under `publicDir` that PostgreSQL only REFERENCES.
 *
 * WHY THESE DIRECTORIES
 * `images/` (Paws' Picture rows) and `support-files/` (support attachments, when a support module
 * stores them) hold real bytes on disk. A directory that does not exist counts as empty.
 * The database dump carries the rows and nothing else, so a restore without this tier brings back
 * pictures and ticket attachments that point at files which no longer exist. `exports/` is left
 * out on purpose: an export is rebuilt on request and its file expires on its own.
 *
 * Uploads can be customer data, so this refuses to run without an
 * age recipient rather than leaving them on a third-party disk in clear.
 */
class FilesBackupService
{
    private const DIRECTORIES = ['images', 'support-files'];

    private const TAR_TIMEOUT = 900;

    public function __construct(
        private Config $config,
        private SettingsService $settings,
        private BackupRunRepository $runs,
        private BackupService $backups,
    ) {
    }

    public function run(string $trigger = 'cron', ?string $actor = null): BackupRun
    {
        $startedAt = UtilsService::GetMicroTime();
        $run = new BackupRun();
        $run->Kind = 'files';
        $run->Status = 'running';
        $run->Trigger = $trigger . ($actor !== null ? ' (' . $actor . ')' : '');
        $run->CreatedOn = $startedAt;
        $run->CreatedBy = $actor;
        $this->runs->create($run);

        $workDir = null;
        try {
            $workDir = $this->backups->makeWorkDirPublic();
            $this->execute($run, $workDir);
            $run->Status = 'success';
        } catch (Throwable $e) {
            $run->Status = 'failed';
            $run->Error = substr($e->getMessage(), 0, 2000);
            echo '[Backup:files] FAILED: ' . $e->getMessage() . PHP_EOL;
        } finally {
            if ($workDir !== null) {
                System::exec('rm -rf ' . escapeshellarg($workDir));
            }
            $run->DurationMs = (int) ((UtilsService::GetMicroTime() - $startedAt) / 1000);
            $run->UpdatedOn = UtilsService::GetMicroTime();
            $this->runs->update($run);
        }
        return $run;
    }

    private function execute(BackupRun $run, string $workDir): void
    {
        $root = (string) $this->config->values['publicDir'];
        $present = [];
        $files = 0;
        foreach (self::DIRECTORIES as $directory) {
            $count = $this->countFiles($root . '/' . $directory);
            if ($count > 0) {
                $present[] = $directory;
                $files += $count;
            }
        }

        if ($files === 0) {
            // Not a failure: a box nobody has uploaded to has nothing here, and saying so beats an
            // empty artifact that reads as a successful backup of something.
            $run->Artifact = 'no uploaded files to back up';
            echo '[Backup:files] nothing under ' . implode(', ', self::DIRECTORIES) . ' - nothing to do' . PHP_EOL;
            return;
        }

        $recipient = trim($this->settings->getString('backup.ageRecipient', ''));
        if ($recipient === '') {
            throw new BackupException(
                'refusing to back up uploaded files without encryption. Set backup.ageRecipient '
                    . 'first: support attachments are customer data.'
            );
        }

        $stamp = gmdate('Ymd\THis\Z');
        $tarPath = $workDir . '/files.tar';
        $tar = $this->backups->runCommand(
            'tar -cf ' . escapeshellarg($tarPath) . ' -C ' . escapeshellarg($root) . ' '
                . implode(' ', array_map('escapeshellarg', $present)) . ' 2>&1',
            self::TAR_TIMEOUT
        );
        if (($tar['code'] ?? 1) !== 0) {
            throw new BackupException('packaging failed: ' . trim((string) ($tar['output'] ?? '')));
        }

        $artifact = $this->backups->artifactPrefix() . '-files-' . $stamp . '.tar.age';
        $enc = $this->backups->runCommand(
            'age -r ' . escapeshellarg($recipient) . ' -o ' . escapeshellarg($workDir . '/' . $artifact)
                . ' ' . escapeshellarg($tarPath) . ' 2>&1',
            self::TAR_TIMEOUT
        );
        if (($enc['code'] ?? 1) !== 0 || !is_file($workDir . '/' . $artifact)) {
            throw new BackupException('age encryption failed: ' . trim((string) ($enc['output'] ?? '')));
        }

        $transport = $this->backups->transportFor($workDir);
        $remoteDir = $this->backups->remoteBase() . '/files';
        $transport->mkdirP($remoteDir);
        $transport->put($workDir . '/' . $artifact, $remoteDir, $artifact);

        $localBytes = (int) filesize($workDir . '/' . $artifact);
        $remoteBytes = $transport->size($remoteDir . '/' . $artifact);
        if ($remoteBytes !== null && $remoteBytes !== $localBytes) {
            throw new BackupException("size mismatch after upload: local $localBytes != remote $remoteBytes");
        }

        $transport->prune($remoteDir, max(1, (int) $this->settings->getInt('backup.retainCount', 7)));

        $run->Artifact = $artifact;
        $run->SizeBytes = $localBytes;
        $run->TablesWithData = $files;
        $run->Encrypted = true;
        echo '[Backup:files] ' . $artifact . ' - ' . $files . ' file(s), ' . $localBytes . ' bytes' . PHP_EOL;
    }

    private function countFiles(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }
        return $count;
    }

    public function lastSuccess(): ?BackupRun
    {
        /** @var ?BackupRun */
        return $this->runs->firstOrDefault(
            [
                [BackupRunMap::PROPERTY_Status, '=', 'success'],
                [BackupRunMap::PROPERTY_Kind, '=', 'files'],
            ],
            [BackupRunMap::PROPERTY_CreatedOn => -1]
        );
    }
}
