<?php

namespace SaasPaws\Ops\Monitoring;

use SaasPaws\Deploy\LiveInstance\LiveInstanceService;
use SaasPaws\Ops\Monitoring\Services\ResourceMailer;
use SaasPaws\Ops\System\Services\SystemService;
use Fluffy\Domain\Configuration\Config;
use Fluffy\Services\Settings\SettingsService;

/**
 * Urlicer's ResourceWeeklyReportTask, renamed: Fluffy's cron keys ("<uid>|<class>|<method>" in a
 * Swoole\Table) must stay under 64 bytes, and the full name ran over.
 *
 * Emails the admin list a weekly resource snapshot (CPU / memory / disk, Redis). Runs weekly in
 * the Swoole task-worker scope (fresh DI scope per run). A point-in-time snapshot at send time — no metric history is stored, so
 * there is nothing to aggregate over the week. No-op (silent) when disabled.
 */
class ResourceReportTask
{
    public function __construct(
        private SystemService $system,
        private ResourceMailer $mailer,
        private SettingsService $settings,
        private Config $config,
        private LiveInstanceService $live,
    ) {}

    public function execute()
    {
        // Blue/green: only the colour taking traffic runs cron, or every job fires
        // twice while both instances are up (see LiveInstanceService).
        if ($this->live->shouldSkipCron('ResourceReportTask')) {
            return;
        }

        if (!$this->settings->getBool('monitoring.weeklyReportEnabled', false)) {
            return;
        }

        $snapshot = [
            'os' => $this->system->getOsInfo(),
            'redis' => $this->system->getRedisInfoForApp(),
        ];

        $count = 0;
        foreach ($this->config->values['admins'] ?? [] as $admin) {
            $email = $admin['Email'] ?? null;
            if ($email) {
                $this->mailer->dispatchWeeklyReport($email, $snapshot);
                $count++;
            }
        }

        echo '[ResourceReportTask] ' . date('Y-m-d H:i:s')
            . " sent {$count} report(s)." . PHP_EOL;
    }
}
