<?php

namespace SaasPaws\Deploy;

use DotDi\DependencyInjection\IServiceProvider;
use DotDi\DependencyInjection\ServiceProviderHelper;
use Fluffy\Data\Context\DbContext;
use Fluffy\Domain\App\BaseApp;
use Fluffy\Domain\App\IStartUp;
use Fluffy\Domain\Configuration\Config;
use Fluffy\Migrations\IMigrationsContext;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployApplicationEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployApplicationEntityMap;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployApplicationServerEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployApplicationServerEntityMap;
use SaasPaws\Deploy\Deploy\Data\Entities\DeploymentHistoryEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeploymentHistoryEntityMap;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployServerEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployServerEntityMap;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployServiceEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployServiceEntityMap;
use SaasPaws\Deploy\Deploy\Data\Entities\DeploySourceEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeploySourceEntityMap;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployUpstreamEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployUpstreamEntityMap;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeployApplicationRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeployApplicationServerRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeploymentHistoryRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeployServerRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeployServiceRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeploySourceRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeployUpstreamRepository;
use SaasPaws\Deploy\Deploy\Services\DeployLogBuffer;
use SaasPaws\Deploy\Deploy\Services\DeploymentService;
use SaasPaws\Deploy\Deploy\Services\ServerInspector;
use SaasPaws\Deploy\Deploy\Services\ServerProvisioner;
use SaasPaws\Deploy\Deploy\Tasks\DeployRunTask;
use SaasPaws\Deploy\LiveInstance\LiveInstanceService;
use SaasPaws\Deploy\Migrations\MigrationsContext;
use SaasPaws\Deploy\Migrations\MigrationsMark;

/**
 * SaasPaws Deploy module: the in-app deploy admin (applications, servers, sources, blue/green
 * rotation over SSH), the Ssh service it drives, and LiveInstanceService (which colour runs cron).
 *
 * Registered by the app with `useStartUp(DeployStartUp::class)` after Paws. Depends on Fluffy,
 * Paws (Pupils admin layout and guards) and Viewi only. App values come from config: `devMode`
 * (deploy enabled) and the release layout LiveInstanceService reads.
 */
class DeployStartUp implements IStartUp
{
    private ?Config $config = null;

    public function configureServices(IServiceProvider $serviceProvider): void
    {
        $this->config = $serviceProvider->get(Config::class);
        DeployPermissions::register();
        // Ungated on purpose: a PROD instance reads this to decide whether it is the colour that
        // runs cron, so it must exist exactly where the deploy services do not.
        // Singleton so the short liveness cache is shared instead of per-scope.
        $serviceProvider->addSingleton(LiveInstanceService::class);
        // Repositories. Ungated: the migrations create these tables on every box and resolve
        // their repositories through DI, and runMigrations() boots configureServices() first;
        // gating them breaks `php fluffy migrate` on prod.
        $serviceProvider->addScoped(DeployServerRepository::class);
        $serviceProvider->addScoped(DeploySourceRepository::class);
        $serviceProvider->addScoped(DeployApplicationRepository::class);
        $serviceProvider->addScoped(DeployUpstreamRepository::class);
        $serviceProvider->addScoped(DeployServiceRepository::class);
        $serviceProvider->addScoped(DeployApplicationServerRepository::class);
        $serviceProvider->addScoped(DeploymentHistoryRepository::class);
        // The deploy engine itself IS gated: it is the thing that can reach out and change a
        // server, so a prod box should not be able to resolve one. The log buffer goes with it:
        // nothing else writes to it.
        if ($this->isDeployEnabled()) {
            $serviceProvider->addScoped(DeploymentService::class);
            $serviceProvider->addScoped(ServerInspector::class);
            $serviceProvider->addScoped(ServerProvisioner::class);
            $serviceProvider->addScoped(DeployLogBuffer::class);
            // The task worker resolves this by class name out of a fresh scope: an unregistered
            // task is a run that dies in the worker with nothing in the log the page is tailing.
            $serviceProvider->addScoped(DeployRunTask::class);
        }
        // Controllers (every handler checks ManageDeployments; the routes are dev-only)
        ServiceProviderHelper::discover($serviceProvider, [__DIR__ . DIRECTORY_SEPARATOR . 'Deploy' . DIRECTORY_SEPARATOR . 'Controllers']);
    }

    /**
     * Is the deployment pipeline part of THIS box's runtime? Dev only.
     *
     * Deploys run from a local instance over SSH to the target box, so prod never needs the
     * pipeline, and a prod box that cannot resolve a deploy service cannot be talked into
     * running one. Applies to the deploy SERVICES and routes, not the repositories (see above).
     */
    public function isDeployEnabled(): bool
    {
        return !empty($this->config?->values['devMode']);
    }

    public function configureDb(IServiceProvider $serviceProvider): void
    {
        DbContext::registerEntity(DeployServerEntity::class, DeployServerEntityMap::class);
        DbContext::registerEntity(DeploySourceEntity::class, DeploySourceEntityMap::class);
        DbContext::registerEntity(DeployApplicationEntity::class, DeployApplicationEntityMap::class);
        DbContext::registerEntity(DeployUpstreamEntity::class, DeployUpstreamEntityMap::class);
        DbContext::registerEntity(DeployServiceEntity::class, DeployServiceEntityMap::class);
        DbContext::registerEntity(DeployApplicationServerEntity::class, DeployApplicationServerEntityMap::class);
        DbContext::registerEntity(DeploymentHistoryEntity::class, DeploymentHistoryEntityMap::class);
    }

    public function configure(BaseApp $app)
    {
        $viewiApp = $app->getProvider()->get(\Viewi\App::class);
        $viewiConfig = $viewiApp->getConfig();
        $viewiConfig->use(DeployViewiPackage::class);
        // Child components of the admin pages (run panel, rows editor) go to the admin bundle too.
        // Viewi matches these prefixes against the class's namespace, which has no trailing
        // backslash: a prefix ending in a backslash never matches classes directly in that folder.
        $viewiConfig->lazyLoadNamespace['SaasPaws\\Deploy\\Deploy\\Components\\Views'] = 'admin';
        include_once __DIR__ . '/routes.php';
    }

    public function configureMigrations(IServiceProvider $serviceProvider): void
    {
        ServiceProviderHelper::discover($serviceProvider, [MigrationsMark::folder()]);
        $serviceProvider->addScoped(IMigrationsContext::class, MigrationsContext::class);
    }

    public function configureInstallDependencies(IServiceProvider $serviceProvider): void {}

    public function buildDependencies(IServiceProvider $serviceProvider) {}
}
