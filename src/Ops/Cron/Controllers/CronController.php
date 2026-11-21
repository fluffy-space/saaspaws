<?php

namespace SaasPaws\Ops\Cron\Controllers;

use SaasPaws\Ops\OpsCapability;
use Fluffy\Controllers\BaseController;
use Fluffy\Services\Auth\AuthorizationService;
use Fluffy\Swoole\Task\CronMonitorService;

class CronController extends BaseController
{
    function __construct(
        protected CronMonitorService $cron,
        protected AuthorizationService $auth,
    ) {}

    function List()
    {
        if (!$this->auth->authorizeAdminCapability(OpsCapability::ViewCron)) {
            return $this->Forbidden();
        }
        return [
            'jobs' => $this->cron->list(),
            'health' => $this->cron->health(),
        ];
    }

    function Pause()
    {
        if (!$this->auth->authorizeAdminCapability(OpsCapability::ManageCron)) {
            return $this->Forbidden();
        }
        $this->cron->pauseAll($this->actor());
        return ['health' => $this->cron->health()];
    }

    function Resume()
    {
        if (!$this->auth->authorizeAdminCapability(OpsCapability::ManageCron)) {
            return $this->Forbidden();
        }
        $this->cron->resumeAll($this->actor());
        return ['health' => $this->cron->health()];
    }

    /** Email of the admin performing the action, for the audit field (best-effort). */
    private function actor(): string
    {
        return $this->auth->getAuthorizedUser()->Email ?? '';
    }
}
