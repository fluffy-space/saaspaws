<?php

namespace SaasPaws\Ops\Logs\Controllers;

use SaasPaws\Ops\OpsCapability;
use SaasPaws\Ops\Logs\Services\LogService;
use Fluffy\Controllers\BaseController;
use Fluffy\Services\Auth\AuthorizationService;

class LogController extends BaseController
{
    function __construct(
        protected LogService $logs,
        protected AuthorizationService $auth,
    ) {}

    /**
     * GET /api/admin/logs?lines=&priority=&before=&after=&unit= — a page of the app's systemd journal.
     * before/after are boundary cursors for paging older/newer; omit both for the latest page.
     * unit restricts the merged blue/green stream to one configured unit (blank => all).
     */
    function Tail(int $lines = 200, ?string $priority = null, ?string $before = null, ?string $after = null, ?string $unit = null)
    {
        if (!$this->auth->authorizeAdminCapability(OpsCapability::ViewSystemInfo)) {
            return $this->Forbidden();
        }
        return $this->logs->page($lines, $priority, $before, $after, $unit);
    }
}
