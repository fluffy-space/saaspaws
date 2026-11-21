<?php

use SaasPaws\Ops\Cron\Components\Views\CronManagementPage;
use SaasPaws\Ops\Cron\Controllers\CronController;
use SaasPaws\Ops\Logs\Components\Views\LogsPage;
use SaasPaws\Ops\Logs\Controllers\LogController;
use SaasPaws\Ops\System\Components\Views\SystemInfoPage;
use SaasPaws\Ops\System\Controllers\SystemController;
use Viewi\App;
use Viewi\Router\Router;

/**
 * Ops module routes, included from OpsStartUp::configure(). Every handler checks its capability
 * (SuperAdmin only); the pages carry the HasCapability guard.
 *
 * @var App $viewiApp
 */
$router = $viewiApp->router();

$router->section('/api/', function (Router $router) {
    $router->section('admin/', function (Router $router) {
        // Journal viewer: the blue/green units' journald
        $router->get('logs', [LogController::class, 'Tail']);
        // Host, Redis and process figures
        $router->get('system', [SystemController::class, 'GetSystemInfo']);
        // Cron jobs (Fluffy's CronMonitorService): list, global pause / resume
        $router->get('cron', [CronController::class, 'List']);
        $router->post('cron/pause', [CronController::class, 'Pause']);
        $router->post('cron/resume', [CronController::class, 'Resume']);
    });
});

// Admin pages (lazy bundle, next to the Paws admin pages)
$router->lazy('admin', function (Router $router) {
    $router->section('admin', function (Router $router) {
        $router->get('/logs', LogsPage::class);
        $router->get('/system', SystemInfoPage::class);
        $router->get('/cron', CronManagementPage::class);
    });
});
