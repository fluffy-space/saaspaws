<?php

use SaasPaws\Deploy\Deploy\Components\Views\DeployApplicationEdit;
use SaasPaws\Deploy\Deploy\Components\Views\DeployApplicationList;
use SaasPaws\Deploy\Deploy\Components\Views\DeployServerEdit;
use SaasPaws\Deploy\Deploy\Components\Views\DeployServerList;
use SaasPaws\Deploy\Deploy\Components\Views\DeploySourceEdit;
use SaasPaws\Deploy\Deploy\Components\Views\DeploySourceList;
use SaasPaws\Deploy\Deploy\Controllers\DeployApplicationController;
use SaasPaws\Deploy\Deploy\Controllers\DeployController;
use SaasPaws\Deploy\Deploy\Controllers\DeployServerController;
use SaasPaws\Deploy\Deploy\Controllers\DeploySourceController;
use Viewi\App;
use Viewi\Router\Router;

/**
 * Deploy module routes, included from DeployStartUp::configure() (so `$this` is DeployStartUp).
 *
 * @var App $viewiApp
 */
$router = $viewiApp->router();

$router->section('/api/', function (Router $router) {
    $router->section('admin/', function (Router $router) {
        // Deployments (copied from Urlicer) - DEV-ONLY: outside devMode these routes are never
        // registered, so prod exposes no deploy surface at all. Every handler still checks
        // ManageDeployments (SuperAdmin-only), because controller discovery is folder-wide and the
        // classes exist everywhere. `$this` is DeployStartUp (this file is included from its configure()).
        if ($this->isDeployEnabled()) {
            // literal 'deploy/run/...' and 'deploy/history/...' BEFORE the bare 'deploy/{id}/...'
            $router->get('deploy/run/{runId}/logs', [DeployController::class, 'Logs']);
            $router->get('deploy/history/{id}', [DeployController::class, 'HistoryLog']);
            $router->post('deploy/{id}/run', [DeployController::class, 'Run']);
            $router->post('deploy/{id}/vault', [DeployController::class, 'PushVault']);
            $router->post('deploy/{id}/restart', [DeployController::class, 'Restart']);
            $router->post('deploy/{id}/check', [DeployController::class, 'Check']);
            $router->post('deploy/{id}/setup', [DeployController::class, 'SetUp']);
            $router->post('deploy/{id}/certificate', [DeployController::class, 'Certificate']);
            $router->get('deploy/{id}/history', [DeployController::class, 'History']);

            // CRUD for the deploy entities themselves (the ListPage/EditPage convention).
            $router->get('deploy-server', [DeployServerController::class, 'List']);
            $router->post('deploy-server', [DeployServerController::class, 'Create']);
            $router->get('deploy-server/{id}', [DeployServerController::class, 'Get']);
            $router->put('deploy-server/{id}', [DeployServerController::class, 'Update']);
            $router->delete('deploy-server/{id}', [DeployServerController::class, 'Delete']);

            $router->get('deploy-source', [DeploySourceController::class, 'List']);
            $router->post('deploy-source', [DeploySourceController::class, 'Create']);
            $router->get('deploy-source/{id}', [DeploySourceController::class, 'Get']);
            $router->put('deploy-source/{id}', [DeploySourceController::class, 'Update']);
            $router->delete('deploy-source/{id}', [DeploySourceController::class, 'Delete']);

            $router->get('deploy-application', [DeployApplicationController::class, 'List']);
            // literal 'servers' / 'sources' / 'generate' before the bare {id}
            $router->get('deploy-application/servers', [DeployApplicationController::class, 'Servers']);
            $router->get('deploy-application/sources', [DeployApplicationController::class, 'Sources']);
            $router->post('deploy-application/generate', [DeployApplicationController::class, 'Generate']);
            $router->post('deploy-application', [DeployApplicationController::class, 'Create']);
            $router->get('deploy-application/{id}', [DeployApplicationController::class, 'Get']);
            $router->put('deploy-application/{id}', [DeployApplicationController::class, 'Update']);
            $router->delete('deploy-application/{id}', [DeployApplicationController::class, 'Delete']);
        }
    });
});

// Admin pages (lazy bundle, next to the Paws admin pages). The pages need ManageDeployments; the
// API routes above only exist in devMode. Literal 'create' before the bare {id} on each: the
// client router ignores declaration order for a {param} that could shadow a literal.
$router->lazy('admin', function (Router $router) {
    $router->section('admin', function (Router $router) {
        $router->get('/deploy-application', DeployApplicationList::class);
        $router->get('/deploy-application/create', DeployApplicationEdit::class);
        $router->get('/deploy-application/{id}', DeployApplicationEdit::class);
        $router->get('/deploy-server', DeployServerList::class);
        $router->get('/deploy-server/create', DeployServerEdit::class);
        $router->get('/deploy-server/{id}', DeployServerEdit::class);
        $router->get('/deploy-source', DeploySourceList::class);
        $router->get('/deploy-source/create', DeploySourceEdit::class);
        $router->get('/deploy-source/{id}', DeploySourceEdit::class);
    });
});
