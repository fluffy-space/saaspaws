<?php

namespace SaasPaws\Ops\System\Controllers;

use SaasPaws\Ops\OpsCapability;
use SaasPaws\Ops\System\Services\SystemService;
use Fluffy\Controllers\BaseController;
use Fluffy\Services\Auth\AuthorizationService;

class SystemController extends BaseController
{
    function __construct(
        protected SystemService $systemService,
        protected AuthorizationService $auth,
    ) {}

    function GetSystemInfo()
    {
        if (!$this->auth->authorizeAdminCapability(OpsCapability::ViewSystemInfo)) {
            return $this->Forbidden();
        }
        $redisInfo = $this->systemService->getRedisInfoForApp();
        $osInfo = $this->systemService->getOsInfo();
        $processes = $this->systemService->getProcessesInfo();
        return ['redis' => $redisInfo, 'os' => $osInfo, 'processes' => $processes];
    }
}
