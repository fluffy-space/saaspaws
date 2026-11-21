<?php

use SaasPaws\Backup\Backups\Components\Views\BackupManagementPage;
use SaasPaws\Backup\Backups\Controllers\BackupController;
use Viewi\App;
use Viewi\Router\Router;

/**
 * Backup module routes, included from BackupStartUp::configure(). Every handler checks
 * ViewBackups / ManageBackups (SuperAdmin only); the page carries the HasCapability guard.
 *
 * @var App $viewiApp
 */
$router = $viewiApp->router();

$router->section('/api/', function (Router $router) {
    $router->section('admin/', function (Router $router) {
        // literal 'backup/...' paths; the bare 'backup' list (ListPage) last
        $router->get('backup/status', [BackupController::class, 'Status']);
        $router->get('backup/remote', [BackupController::class, 'Remote']);
        $router->post('backup/run', [BackupController::class, 'Run']);
        $router->post('backup/verify', [BackupController::class, 'Verify']);
        $router->post('backup/files', [BackupController::class, 'RunFiles']);
        $router->get('backup', [BackupController::class, 'List']);
    });
});

// Admin page (lazy bundle, next to the Paws admin pages)
$router->lazy('admin', function (Router $router) {
    $router->section('admin', function (Router $router) {
        $router->get('/backups', BackupManagementPage::class);
    });
});
