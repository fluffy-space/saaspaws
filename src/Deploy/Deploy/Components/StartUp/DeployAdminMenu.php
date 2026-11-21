<?php

namespace SaasPaws\Deploy\Deploy\Components\StartUp;

use Pupils\Components\Models\Menu\MenuItem;
use Pupils\Components\Services\Dashboard\AdminMenuService;
use Viewi\Components\IStartUp\IStartUp;

/** Deploy items in the Paws admin menu (found by the Viewi builder as an IStartUp). */
class DeployAdminMenu implements IStartUp
{
    public function __construct(private AdminMenuService $menuService) {}

    public function setUp()
    {
        // The pages need ManageDeployments and the API routes only exist in devMode, so on a
        // production box these lead nowhere by design.
        $this->menuService->addMenuItem(
            new MenuItem('Applications', 94, '/admin/deploy-application', 'bi-rocket-takeoff', ['/admin/deploy-application/create'], "#^/admin/deploy-application(.*)$#i")
        );
        $this->menuService->addMenuItem(
            new MenuItem('Deploy servers', 95, '/admin/deploy-server', 'bi-hdd-network', ['/admin/deploy-server/create'], "#^/admin/deploy-server(.*)$#i")
        );
        $this->menuService->addMenuItem(
            new MenuItem('Deploy sources', 96, '/admin/deploy-source', 'bi-git', ['/admin/deploy-source/create'], "#^/admin/deploy-source(.*)$#i")
        );
    }
}
