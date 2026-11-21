<?php

namespace SaasPaws\Backup\Backups\Components\Emails;

use Viewi\Components\BaseComponent;
use Viewi\Components\Config\ConfigService;

/**
 * Server-side-only email telling admins that a scheduled backup failed. Driven by BackupTask;
 * carries one row per failed tier (database, files) with its error, and a link to /admin/backups.
 * BackupStartUp puts this namespace in noJsNamespace, so it never ships to the browser.
 */
class BackupAlertEmail extends BaseComponent
{
    public static bool $_noBrowser = true;

    public string $baseUrl = '/';
    /** @var array list of ['label','error'] */
    public array $failures = [];

    public function __construct(array $failures, ConfigService $configService)
    {
        $this->failures = $failures;
        $this->baseUrl = $configService->get('baseUrl');
    }
}
