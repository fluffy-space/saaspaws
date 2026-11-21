<?php

namespace SaasPaws\Backup\Backups\Services;

use Fluffy\Domain\Configuration\Config;
use Fluffy\Swoole\Task\TaskManager;
use FluffyPaws\Services\Emails\EmailLogService;
use SaasPaws\Backup\Backups\Components\Emails\BackupAlertEmail;
use Viewi\App;

/**
 * Sends the "backup failed" email (from Urlicer's ResourceMailer::sendBackupAlert, moved here so the
 * Backup module does not depend on Ops). Rendered from the server-only BackupAlertEmail component
 * and delivered off the Swoole task pool via EmailLogService (one EmailLog row per admin).
 *
 * dispatchBackupAlert pushes a first-class method reference onto the task pool: TaskManager
 * serializes the closure via reflection, so it must be `$this->send*(...)`, not an inline closure.
 * The subject starts with "[<email.from_name>]" from config.
 */
class BackupMailer
{
    public function __construct(
        private TaskManager $tasks,
        private App $viewiApp,
        private EmailLogService $emailLog,
        private Config $config,
    ) {}

    /** @param array $failures list of ['label','error'] */
    public function dispatchBackupAlert(string $email, array $failures): void
    {
        $this->tasks->dispatch($this->sendBackupAlert(...), $email, $failures);
    }

    public function sendBackupAlert(string $email, array $failures): void
    {
        $html = $this->viewiApp->engine()->render(
            BackupAlertEmail::class,
            ['failures' => $failures]
        );
        $summary = implode(', ', array_map(fn($f) => $f['label'], $failures));
        $name = trim((string) ($this->config->values['email']['from_name'] ?? ''));
        $subject = ($name !== '' ? "[$name] " : '') . "Backup failed: {$summary}";
        $altBody = "Backup failed: {$summary}. See /admin/backups.";
        $this->emailLog->send('backup-alert', $email, $subject, $html->body, '', $altBody);
    }
}
