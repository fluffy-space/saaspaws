<?php

namespace SaasPaws\Ops\Components\StartUp;

use Pupils\Components\Models\Menu\MenuItem;
use Pupils\Components\Services\Dashboard\AdminMenuService;
use Viewi\Components\IStartUp\IStartUp;

/** Ops items in the Paws admin menu (found by the Viewi builder as an IStartUp). SuperAdmin only. */
class OpsAdminMenu implements IStartUp
{
    public function __construct(private AdminMenuService $menuService) {}

    public function setUp()
    {
        $this->menuService->addMenuItem(
            new MenuItem('System', 90, '/admin/system', 'bi-motherboard', [], "#^/admin/system(.*)$#i")
        );
        $this->menuService->addMenuItem(
            new MenuItem('Cron', 91, '/admin/cron', 'bi-clock-history', [], "#^/admin/cron(.*)$#i")
        );
        // Journal viewer: the blue/green units' journald (config journal.units)
        $this->menuService->addMenuItem(
            new MenuItem('Logs', 92, '/admin/logs', 'bi-journal-text', [], "#^/admin/logs(.*)$#i")
        );
    }
}
