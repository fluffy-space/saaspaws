<?php

namespace SaasPaws\Deploy\Deploy\Tasks;

use SaasPaws\Deploy\Deploy\Services\DeployLogBuffer;
use SaasPaws\Deploy\Deploy\Services\DeploymentService;
use Throwable;

/**
 * Runs a deploy off the request thread.
 *
 * A deploy is minutes long: held inside the HTTP request it would hit every timeout between the
 * browser and the worker, and block one of the local app's two request workers while it ran. The
 * controller validates and hands off here, returning a run id the page then tails.
 *
 * NOT scheduled in `Application/crontab.php` — this one is dispatched on demand, so it needs no
 * live-colour guard (and the control plane is a local instance, which never rotates).
 */
class DeployRunTask
{
    public function __construct(
        private DeploymentService $deployments,
        private DeployLogBuffer $logs,
    ) {}

    /**
     * @param string $kind    'deploy' | 'vault' | 'restart' | 'check'
     * @param array  $context ['userName' => ?string]
     */
    public function execute(int $applicationId, string $kind, array $context, int $runId): void
    {
        // Stream into the buffer the page is tailing. The service writes its own history rows;
        // the run id is only the handle the UI was given synchronously, before those exist.
        $this->deployments->onLog(function (string $chunk) use ($runId) {
            $this->logs->append($runId, $chunk);
        });

        try {
            $records = match ($kind) {
                'vault' => $this->deployments->deployKeyVault($applicationId, $context),
                'restart' => $this->deployments->restart($applicationId, $context),
                'check' => $this->deployments->check($applicationId, $context),
                default => $this->deployments->deploy($applicationId, $context),
            };

            foreach ($records as $record) {
                $this->logs->append($runId, sprintf(
                    "\n[done] #%d %s%s\n",
                    $record->Id,
                    $record->ToSlot !== null ? ($record->FromSlot ?? '-') . ' -> ' . $record->ToSlot . ' ' : '',
                    $record->IsSuccessful ? 'OK' : 'FAILED'
                ));
            }
        } catch (Throwable $e) {
            // The service already recorded the failure on the history row; this line is for the
            // person watching the log, who would otherwise just see the output stop.
            $this->logs->append($runId, "\n[failed] " . $e->getMessage() . "\n");
            echo '[DeployRunTask] ' . $e->getMessage() . PHP_EOL;
        }
    }
}
