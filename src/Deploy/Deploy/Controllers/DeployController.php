<?php

namespace SaasPaws\Deploy\Deploy\Controllers;

use SaasPaws\Deploy\Deploy\Data\Entities\DeployApplicationEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeploymentHistoryEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeploymentHistoryEntityMap;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeployApplicationRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeployServerRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeploymentHistoryRepository;
use SaasPaws\Deploy\DeployCapability;
use SaasPaws\Deploy\Deploy\Services\DeployLogBuffer;
use SaasPaws\Deploy\Deploy\Tasks\DeployRunTask;
use Fluffy\Controllers\BaseController;
use Fluffy\Data\Connector\RedisConnector;
use Fluffy\Domain\Message\HttpRequest;
use Fluffy\Services\Auth\AuthorizationService;
use Fluffy\Swoole\Task\TaskManager;
use Throwable;

/**
 * The Deploy button's API (`/api/admin/deploy`).
 *
 * Two actions and two read endpoints — deliberately no elevation, preview token, typed
 * confirmation, lock or cooldown. The rework dropped all of that: one button, one "are you sure?"
 * modal, exactly as in server-admin.
 *
 * Every handler checks `ManageDeployments` (granted to no role — SuperAdmin only), and the routes
 * are registered in devMode only, so a production box exposes no deploy surface at all. The
 * capability check is still the real gate, because controller discovery is folder-wide.
 *
 * The work itself never happens in the request: a deploy outlives any sane HTTP timeout and would
 * tie up one of the local app's request workers while it ran.
 */
class DeployController extends BaseController
{
    public function __construct(
        private DeployApplicationRepository $applications,
        private DeploymentHistoryRepository $history,
        private DeployServerRepository $servers,
        private DeployLogBuffer $logs,
        private TaskManager $tasks,
        private RedisConnector $redis,
        private AuthorizationService $auth,
        private HttpRequest $request,
    ) {}

    /** POST /api/admin/deploy/{id}/run — build and ship. */
    public function Run(int $id)
    {
        return $this->start($id, 'deploy');
    }

    /**
     * POST /api/admin/deploy/{id}/restart — restart onto the release that is already live.
     *
     * For after the server itself was updated (PHP, Swoole, the OS): no clone, no build, no
     * migration. A rotating application copies its live release into the idle slot and rotates,
     * so it costs no downtime.
     */
    public function Restart(int $id)
    {
        return $this->start($id, 'restart');
    }

    /**
     * POST /api/admin/deploy/{id}/vault — write the key vault to the application's servers.
     *
     * Its own action rather than a step of the deploy: production secrets should change when
     * someone means to change them, and a fresh box needs the vault before the first build reads
     * it. Dispatched like a deploy so it lands in the same history and the same log tail.
     */
    public function PushVault(int $id)
    {
        return $this->start($id, 'vault');
    }

    /**
     * POST /api/admin/deploy/{id}/check: a read-only look at the application's servers
     * (DeploymentService::check): prerequisites, what is already there, what is in the way.
     */
    public function Check(int $id)
    {
        return $this->start($id, 'check');
    }

    /**
     * POST /api/admin/deploy/{id}/setup: prepare a fresh box and make the first release live
     * (DeploymentService::setup). Refuses where the application already runs.
     */
    public function SetUp(int $id)
    {
        return $this->start($id, 'setup');
    }

    /**
     * GET /api/admin/deploy/run/{runId}/logs?offset=N — tail a run in flight.
     *
     * Offset-based so the page appends instead of re-fetching a growing blob every second.
     */
    public function Logs(int $runId)
    {
        if (!$this->auth->authorizeAdminCapability(DeployCapability::ManageDeployments)) {
            return $this->Forbidden();
        }
        $offset = (int) ($this->request->query['offset'] ?? 0);
        return $this->logs->read($runId, max(0, $offset));
    }

    /**
     * GET /api/admin/deploy/{id}/history — this application's records, newest first.
     *
     * `IsSuccessful` null means the run is still going; that is what the Deployments tab watches
     * to know when to stop polling.
     */
    public function History(int $id)
    {
        if (!$this->auth->authorizeAdminCapability(DeployCapability::ManageDeployments)) {
            return $this->Forbidden();
        }
        $page = max(1, (int) ($this->request->query['page'] ?? 1));
        $size = min(100, max(1, (int) ($this->request->query['size'] ?? 20)));
        $result = $this->history->search(
            [[DeploymentHistoryEntityMap::PROPERTY_ApplicationId, $id]],
            [DeploymentHistoryEntityMap::PROPERTY_Id => -1],
            $page,
            $size
        );
        $this->history->include(
            $result['list'],
            $this->servers,
            DeploymentHistoryEntityMap::PROPERTY_ServerId,
            DeploymentHistoryEntityMap::PROPERTY_Server
        );
        return [
            'list' => array_map(fn(DeploymentHistoryEntity $record) => [
                'Id' => $record->Id,
                'Kind' => $record->Kind,
                'ServerName' => $record->Server?->Name,
                'StartedOn' => $record->StartedOn,
                'CompletedOn' => $record->CompletedOn,
                'IsSuccessful' => $record->IsSuccessful,
                'FromSlot' => $record->FromSlot,
                'ToSlot' => $record->ToSlot,
                'CommitSha' => $record->CommitSha,
                'CommitMessage' => $record->CommitMessage,
                'TriggeredByName' => $record->TriggeredByName,
                'Error' => $record->Error,
            ], $result['list']),
            'total' => $result['total'],
        ];
    }

    /** GET /api/admin/deploy/history/{id} — one record with its full log. */
    public function HistoryLog(int $id)
    {
        if (!$this->auth->authorizeAdminCapability(DeployCapability::ManageDeployments)) {
            return $this->Forbidden();
        }
        /** @var ?DeploymentHistoryEntity $record */
        $record = $this->history->getById($id);
        if ($record === null) {
            return $this->NotFound();
        }
        return ['Id' => $record->Id, 'Logs' => $record->Logs, 'Error' => $record->Error];
    }

    // ------------------------------------------------------------------ shared

    private function start(int $id, string $kind)
    {
        if (!$this->auth->authorizeAdminCapability(DeployCapability::ManageDeployments)) {
            return $this->Forbidden();
        }
        $user = $this->auth->getAuthorizedUser();
        if ($user === null) {
            return $this->Forbidden();
        }
        /** @var ?DeployApplicationEntity $app */
        $app = $this->applications->getById($id);
        if ($app === null) {
            return $this->NotFound();
        }

        // The run id is minted here, synchronously, because the page needs something to tail
        // before the task worker has done anything at all.
        try {
            $runId = (int) $this->redis->get()->incr('DP:RunSeq');
            $this->tasks->dispatchArray(
                [DeployRunTask::class, 'execute'],
                $id,
                $kind,
                ['userName' => $user->Email ?? (string) $user->Id],
                $runId
            );
        } catch (Throwable $e) {
            return $this->BadRequest(['Could not start the deploy: ' . $e->getMessage()]);
        }
        return ['runId' => $runId, 'kind' => $kind, 'applicationId' => $id, 'name' => $app->Name];
    }
}
