<?php

namespace SaasPaws\Ops;

use DotDi\DependencyInjection\IServiceProvider;
use DotDi\DependencyInjection\ServiceProviderHelper;
use Fluffy\Domain\App\BaseApp;
use Fluffy\Domain\App\IStartUp;
use Fluffy\Domain\CronTab\CronTab;
use Fluffy\Migrations\IMigrationsContext;
use FluffyPaws\Services\Emails\IEmailPreviewProvider;
use SaasPaws\Ops\Logs\Services\LogService;
use SaasPaws\Ops\Migrations\MigrationsContext;
use SaasPaws\Ops\Migrations\MigrationsMark;
use SaasPaws\Ops\Monitoring\Services\MonitoringSettings;
use SaasPaws\Ops\Monitoring\Services\OpsEmailPreviewProvider;
use SaasPaws\Ops\Monitoring\Services\ResourceMailer;
use SaasPaws\Ops\Monitoring\ResourceAlertTask;
use SaasPaws\Ops\Monitoring\ResourceReportTask;
use SaasPaws\Ops\System\Services\SystemService;

/**
 * SaasPaws Ops module: admin log viewer (/admin/logs), system monitor (/admin/system), cron admin
 * (/admin/cron), resource alerts + weekly report emails (settings group `monitoring`).
 *
 * Registered by the app with `useStartUp(OpsStartUp::class)` after DeployStartUp: the cron tasks
 * use Deploy's LiveInstanceService (blue/green: only the live colour runs cron). Otherwise depends
 * on Fluffy, Paws and Viewi only. App values come from config: `redis` (pool and db index),
 * `journal.units`, `admins` (alert recipients), `email.from_name` (subject prefix), `baseUrl`.
 */
class OpsStartUp implements IStartUp
{
    public function configureServices(IServiceProvider $serviceProvider): void
    {
        OpsPermissions::register();
        // Thresholds and toggles, editable live at /admin/settings (group monitoring)
        MonitoringSettings::register();
        $serviceProvider->addScoped(LogService::class);
        $serviceProvider->addScoped(SystemService::class);
        $serviceProvider->addScoped(ResourceMailer::class);
        // Cron jobs are resolved by class name in the task worker: register every scheduled one.
        // Keep cron task names short: Fluffy keys its Swoole cron tables by "<8-char uid>|<FQCN>|<method>",
        // and a Swoole\Table key over 63 bytes is refused (no run lock, no /admin/cron status).
        $serviceProvider->addScoped(ResourceAlertTask::class);
        $serviceProvider->addScoped(ResourceReportTask::class);
        // The two emails on /admin/email-templates
        $serviceProvider->addScoped(IEmailPreviewProvider::class, OpsEmailPreviewProvider::class);
        $d = DIRECTORY_SEPARATOR;
        ServiceProviderHelper::discover($serviceProvider, [
            __DIR__ . $d . 'Logs' . $d . 'Controllers',
            __DIR__ . $d . 'System' . $d . 'Controllers',
            __DIR__ . $d . 'Cron' . $d . 'Controllers',
        ]);
    }

    public function configureDb(IServiceProvider $serviceProvider): void {}

    public function configure(BaseApp $app)
    {
        $viewiApp = $app->getProvider()->get(\Viewi\App::class);
        $viewiConfig = $viewiApp->getConfig();
        $viewiConfig->use(OpsViewiPackage::class);
        // Emails are rendered on the server only, never shipped to the browser.
        // Viewi matches these prefixes against the class's namespace, which has no trailing
        // backslash: a prefix ending in a backslash never matches classes directly in that folder.
        $viewiConfig->noJsNamespace[] = 'SaasPaws\\Ops\\Monitoring\\Components\\Emails';
        // IconCard (used only by the system page) goes to the admin bundle with the pages.
        $viewiConfig->lazyLoadNamespace['SaasPaws\\Ops\\System\\Components\\Views'] = 'admin';
        include_once __DIR__ . '/routes.php';

        // Resource watchdog: email admins when CPU / memory / disk / steal / Redis cross their
        // thresholds, every 5 min. Cooldown + recovery per metric (state in the Redis cache key
        // `monitoring.alertState`); thresholds under Settings > monitoring.
        CronTab::schedule([ResourceAlertTask::class, 'execute'], '0 */5 * * * *');
        // Weekly resource snapshot email to admins, Monday 08:00 ('0 0 8 * * 1', weekday 1).
        CronTab::schedule([ResourceReportTask::class, 'execute'], '0 0 8 * * 1');
    }

    public function configureMigrations(IServiceProvider $serviceProvider): void
    {
        ServiceProviderHelper::discover($serviceProvider, [MigrationsMark::folder()]);
        $serviceProvider->addScoped(IMigrationsContext::class, MigrationsContext::class);
    }

    public function configureInstallDependencies(IServiceProvider $serviceProvider): void {}

    public function buildDependencies(IServiceProvider $serviceProvider) {}
}
