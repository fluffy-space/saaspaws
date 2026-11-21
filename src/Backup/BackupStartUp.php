<?php

namespace SaasPaws\Backup;

use DotDi\DependencyInjection\IServiceProvider;
use DotDi\DependencyInjection\ServiceProviderHelper;
use Fluffy\Data\Context\DbContext;
use Fluffy\Domain\App\BaseApp;
use Fluffy\Domain\App\IStartUp;
use Fluffy\Domain\CronTab\CronTab;
use Fluffy\Migrations\IMigrationsContext;
use FluffyPaws\Services\Emails\IEmailPreviewProvider;
use SaasPaws\Backup\Backups\Data\Entities\BackupRun;
use SaasPaws\Backup\Backups\Data\Entities\BackupRunMap;
use SaasPaws\Backup\Backups\Data\Repositories\BackupRunRepository;
use SaasPaws\Backup\Backups\Services\BackupEmailPreviewProvider;
use SaasPaws\Backup\Backups\Services\BackupMailer;
use SaasPaws\Backup\Backups\Services\BackupService;
use SaasPaws\Backup\Backups\Services\BackupSettings;
use SaasPaws\Backup\Backups\Services\FilesBackupService;
use SaasPaws\Backup\Backups\Tasks\BackupTask;
use SaasPaws\Backup\Migrations\MigrationsContext;
use SaasPaws\Backup\Migrations\MigrationsMark;

/**
 * SaasPaws Backup module: PostgreSQL dump + uploaded files to a Hetzner Storage Box over SFTP,
 * age-encrypted, verified by a restore into a scratch database; /admin/backups; settings group
 * `backup` (destination, keys, schedule, retention: entered at /admin/settings, never in the repo).
 *
 * Registered by the app with `useStartUp(BackupStartUp::class)` after DeployStartUp: BackupTask
 * uses Deploy's LiveInstanceService (only the live colour runs cron). It reads the Ops module's
 * `monitoring.alertCooldownMinutes` setting by key (the default applies without Ops). App values come from config: `postgresql` (database, role, password for the
 * dump), `publicDir` (uploads and the private staging dir), `admins`, `email.from_name`.
 * Needs pg_dump/pg_restore/psql, age, sftp on the box (prod/70-backups.sh installs them).
 */
class BackupStartUp implements IStartUp
{
    public function configureServices(IServiceProvider $serviceProvider): void
    {
        BackupPermissions::register();
        BackupSettings::register();
        $serviceProvider->addScoped(BackupRunRepository::class);
        $serviceProvider->addScoped(BackupService::class);
        $serviceProvider->addScoped(FilesBackupService::class);
        $serviceProvider->addScoped(BackupMailer::class);
        // Resolved by class name in the task worker: the pair to the CronTab::schedule below.
        $serviceProvider->addScoped(BackupTask::class);
        $serviceProvider->addScoped(IEmailPreviewProvider::class, BackupEmailPreviewProvider::class);
        ServiceProviderHelper::discover($serviceProvider, [__DIR__ . DIRECTORY_SEPARATOR . 'Backups' . DIRECTORY_SEPARATOR . 'Controllers']);
    }

    public function configureDb(IServiceProvider $serviceProvider): void
    {
        DbContext::registerEntity(BackupRun::class, BackupRunMap::class);
    }

    public function configure(BaseApp $app)
    {
        $viewiApp = $app->getProvider()->get(\Viewi\App::class);
        $viewiConfig = $viewiApp->getConfig();
        $viewiConfig->use(BackupViewiPackage::class);
        // The alert email is rendered on the server only, never shipped to the browser.
        // Viewi matches these prefixes against the class's namespace, which has no trailing
        // backslash: a prefix ending in a backslash never matches classes directly in that folder.
        $viewiConfig->noJsNamespace[] = 'SaasPaws\\Backup\\Backups\\Components\\Emails';
        include_once __DIR__ . '/routes.php';

        // Ticks every 15 minutes but only RUNS when one is due: the schedule itself is the
        // `backup.schedule` setting (a cron expression evaluated against the last success), so it
        // can change live without a restart. An idle tick is a settings read plus one indexed
        // query; with `backup.enabled` off (the default) it returns at once.
        CronTab::schedule([BackupTask::class, 'execute'], '0 */15 * * * *');
    }

    public function configureMigrations(IServiceProvider $serviceProvider): void
    {
        ServiceProviderHelper::discover($serviceProvider, [MigrationsMark::folder()]);
        $serviceProvider->addScoped(IMigrationsContext::class, MigrationsContext::class);
    }

    public function configureInstallDependencies(IServiceProvider $serviceProvider): void {}

    public function buildDependencies(IServiceProvider $serviceProvider) {}
}
