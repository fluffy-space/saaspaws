<?php

namespace SaasPaws\Backup\Backups\Components\StartUp;

use Pupils\Components\Models\Menu\MenuItem;
use Pupils\Components\Services\Dashboard\AdminMenuService;
use Viewi\Components\IStartUp\IStartUp;

/** Backups in the Paws admin menu (found by the Viewi builder as an IStartUp). SuperAdmin only. */
class BackupAdminMenu implements IStartUp
{
    public function __construct(private AdminMenuService $menuService) {}

    public function setUp()
    {
        $this->menuService->addMenuItem(
            new MenuItem('Backups', 93, '/admin/backups', 'bi-database-down', [], "#^/admin/backups(.*)$#i")
        );
    }
}
