<?php

namespace SaasPaws\Backup\Backups\Controllers;

use SaasPaws\Backup\BackupCapability;
use SaasPaws\Backup\Backups\Components\Models\BackupRunModel;
use SaasPaws\Backup\Backups\Data\Entities\BackupRun;
use SaasPaws\Backup\Backups\Data\Entities\BackupRunMap;
use SaasPaws\Backup\Backups\Data\Repositories\BackupRunRepository;
use SaasPaws\Backup\Backups\Services\BackupService;
use SaasPaws\Backup\Backups\Services\FilesBackupService;
use Fluffy\Controllers\BaseController;
use Fluffy\Services\Auth\AuthorizationService;
use Fluffy\Services\UtilsService;
use Fluffy\Data\Query\Search;

/**
 * Backup status and on-demand runs (copied from Urlicer; design in its docs/backup-plan.md §v2).
 *
 * SuperAdmin-only, like Cron and Deployments — this surface reports on, and can trigger, moving
 * the entire database off the box.
 *
 * Configuration lives in the generic settings page under group `backup`, not here: the settings
 * store already renders and validates by type, and duplicating it would give two places to change
 * a destination and one of them to forget.
 */
class BackupController extends BaseController
{
    function __construct(
        protected BackupService $backups,
        protected FilesBackupService $files,
        protected BackupRunRepository $runs,
        protected AuthorizationService $auth,
    ) {}

    /** Paginated history, in the {list,total} shape ListPage expects. */
    public function List(int $page = 1, int $size = 10, ?string $search = null)
    {
        if (!$this->auth->authorizeAdminCapability(BackupCapability::ViewBackups)) {
            return $this->Forbidden();
        }
        $where = [];
        $search = trim($search ?? '');
        if ($search !== '') {
            $where[] = [
                [BackupRunMap::PROPERTY_Artifact, 'like', Search::contains($search)],
                [BackupRunMap::PROPERTY_Status, 'like', Search::contains($search)],
                [BackupRunMap::PROPERTY_Trigger, 'like', Search::contains($search)],
            ];
        }
        $result = $this->runs->search($where, [BackupRunMap::PROPERTY_CreatedOn => -1], $page, $size);
        return [
            'list' => array_map(fn($entity) => $this->toModel($entity), $result['list']),
            'total' => $result['total'],
        ];
    }

    /**
     * The banner above the list: how stale is the newest good backup, and when is the next due.
     * Separate from List because ListPage owns that request and only reads {list,total}.
     */
    public function Status()
    {
        if (!$this->auth->authorizeAdminCapability(BackupCapability::ViewBackups)) {
            return $this->Forbidden();
        }
        return $this->state();
    }

    /**
     * Run one backup now, synchronously.
     *
     * Deliberately NOT dispatched to a task worker, unlike the cron path: the operator is sitting
     * in front of it waiting to find out whether the destination is configured correctly, and a
     * fire-and-forget that reports success before anything has been uploaded is exactly the wrong
     * feedback for the button whose job is to prove the setup works. Dumps here take seconds;
     * System::exec yields the coroutine, so nothing else on the server stalls meanwhile.
     */
    public function Run()
    {
        if (!$this->auth->authorizeAdminCapability(BackupCapability::ManageBackups)) {
            return $this->Forbidden();
        }
        $run = $this->backups->run('manual', $this->actor());
        // Just the run. The banner and the list each own their own request — duplicating the
        // status envelope here would give two sources for the same numbers and one of them to
        // forget to update.
        return ['run' => $this->toModel($run)];
    }

    /**
     * Verify a backup by restoring it into a scratch database. Synchronous for the same reason
     * Run is: the operator is waiting to learn whether their backups are actually restorable, and
     * that answer is the entire product of this button.
     */
    public function Verify(?string $artifact = null)
    {
        if (!$this->auth->authorizeAdminCapability(BackupCapability::ManageBackups)) {
            return $this->Forbidden();
        }
        // A named artifact reaches an sftp batch line and a local path: accept only one of ours
        // that is actually at the restore base.
        if ($artifact !== null && $artifact !== '') {
            try {
                if (!$this->backups->isRestorableArtifact($artifact)) {
                    return $this->BadRequest('Unknown artifact.');
                }
            } catch (\Throwable $e) {
                return $this->BadRequest($e->getMessage());
            }
        }
        $run = $this->backups->verify($artifact, 'manual', $this->actor());
        return ['run' => $this->toModel($run)];
    }

    /** Back up the uploaded files (pictures, support attachments) the database only references. */
    public function RunFiles()
    {
        if (!$this->auth->authorizeAdminCapability(BackupCapability::ManageBackups)) {
            return $this->Forbidden();
        }
        $run = $this->files->run('manual', $this->actor());
        return ['run' => $this->toModel($run)];
    }

    /**
     * What is on the Storage Box, as opposed to what this instance remembers doing. On the
     * deployer with `backup.restoreBase` pointed at production, this is the list of PRODUCTION
     * artifacts — the thing you need during a recovery and the one thing the local run history
     * cannot tell you.
     */
    public function Remote()
    {
        if (!$this->auth->authorizeAdminCapability(BackupCapability::ViewBackups)) {
            return $this->Forbidden();
        }
        try {
            return $this->backups->remoteInventory();
        } catch (\Throwable $e) {
            return $this->BadRequest($e->getMessage());
        }
    }

    private function state(): array
    {
        $last = $this->backups->lastSuccess();
        $lastRun = $this->backups->lastRun();
        $lastVerify = $this->backups->lastVerify();
        return [
            'lastSuccess' => $last === null ? null : $this->toModel($last),
            // The number the operator actually needs: how stale is the newest good backup?
            // Null means there has never been one, which is a different — and worse — answer
            // than "it is old", so the two are not collapsed into a single figure.
            'lastSuccessAgeMinutes' => $last === null
                ? null
                : (int) ((UtilsService::GetMicroTime() - (int) $last->CreatedOn) / 60_000_000),
            // The newest ATTEMPT, so a run that failed last night is visible at the top instead
            // of being inferred from the absence of a fresh success.
            'lastRun' => $lastRun === null ? null : $this->toModel($lastRun),
            'lastRunAgeMinutes' => $lastRun === null
                ? null
                : (int) ((UtilsService::GetMicroTime() - (int) $lastRun->CreatedOn) / 60_000_000),
            'due' => $this->backups->isDue(),
            'nextDueAt' => $this->backups->nextDueAt(),
            // Backups being taken says nothing about their being restorable — the two questions
            // are shown separately because a green backup light with no verification behind it is
            // exactly the false comfort this feature exists to remove.
            'lastVerify' => $lastVerify === null ? null : $this->toModel($lastVerify),
            'lastVerifyAgeMinutes' => $lastVerify === null
                ? null
                : (int) ((UtilsService::GetMicroTime() - (int) $lastVerify->CreatedOn) / 60_000_000),
        ];
    }

    private function toModel(BackupRun $run): BackupRunModel
    {
        $model = new BackupRunModel();
        $model->Id = $run->Id;
        $model->CreatedOn = $run->CreatedOn;
        $model->Kind = $run->Kind;
        $model->Status = $run->Status;
        $model->Trigger = $run->Trigger;
        $model->Artifact = $run->Artifact;
        $model->SizeBytes = $run->SizeBytes;
        $model->TablesWithData = $run->TablesWithData;
        $model->DurationMs = $run->DurationMs;
        $model->Encrypted = $run->Encrypted;
        $model->Error = $run->Error;
        return $model;
    }

    private function actor(): string
    {
        return $this->auth->getAuthorizedUser()->Email ?? '';
    }
}
