<?php

namespace SaasPaws\Backup\Backups\Services;

use FluffyPaws\Services\Emails\EmailPreviewContext;
use FluffyPaws\Services\Emails\EmailPreviewTemplate;
use FluffyPaws\Services\Emails\IEmailPreviewProvider;
use SaasPaws\Backup\Backups\Components\Emails\BackupAlertEmail;

/** The "backup failed" email on /admin/email-templates, rendered with sample data. */
class BackupEmailPreviewProvider implements IEmailPreviewProvider
{
    public function templates(): array
    {
        return [
            new EmailPreviewTemplate(
                'backup-alert',
                'Backup failed',
                fn(EmailPreviewContext $ctx) => $ctx->viewiApp->engine()->render(
                    BackupAlertEmail::class,
                    ['failures' => [
                        ['label' => 'Database', 'error' => 'sftp: Connection timed out'],
                        ['label' => 'Files', 'error' => 'sftp: Connection timed out'],
                    ]]
                )
            ),
        ];
    }
}
