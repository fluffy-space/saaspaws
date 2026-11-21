<?php

namespace SaasPaws\Backup\Backups\Tasks;

use SaasPaws\Backup\Backups\Services\BackupMailer;
use SaasPaws\Backup\Backups\Services\BackupService;
use SaasPaws\Backup\Backups\Services\FilesBackupService;
use SaasPaws\Deploy\LiveInstance\LiveInstanceService;
use Fluffy\Domain\Configuration\Config;
use Fluffy\Services\Cache\RedisCache;
use Fluffy\Services\Settings\SettingsService;
use Throwable;

/**
 * Takes the PostgreSQL + uploaded-files backup when one is due (copied from Urlicer; design in its
 * docs/backup-plan.md §v2).
 *
 * WHY THE CRON TICKS OFTEN AND THE TASK DECIDES
 * The schedule lives in the `backup` settings group as an interval, not in crontab.php as a cron
 * expression, because cron schedules only change on a full restart and the whole point of this
 * design is that the operator moves from daily to hourly from a settings page. So the tick is
 * frequent and cheap — a settings read and, usually, one indexed query — and BackupService::isDue()
 * owns the actual decision.
 *
 * The dump itself is dispatched to a TASK WORKER rather than run here: it spawns pg_dump, age and
 * sftp, and a multi-minute upload on the cron coroutine would hold up every other scheduled job.
 *
 * A FAILED RUN EMAILS THE ADMIN LIST
 * The database backup is retried on every tick until it succeeds, so the alert follows the same
 * cooldown-and-recovery rule as the Ops module's ResourceAlertTask. Its own switch,
 * `backup.alertsEnabled` (on by default: unlike the host alerts, nobody else sends these), and the
 * shared `monitoring.alertCooldownMinutes`: one email when a run fails, a
 * reminder per cooldown while it keeps failing, and a clean run re-arms it.
 */
class BackupTask
{
    private const ALERT_STATE_KEY = 'backup.alertState';

    public function __construct(
        private BackupService $backups,
        private FilesBackupService $files,
        private LiveInstanceService $live,
        private SettingsService $settings,
        private BackupMailer $mailer,
        private RedisCache $cache,
        private Config $config,
    ) {
    }

    public function execute()
    {
        // Blue/green: only the colour taking traffic runs cron, or a backup fires twice — two
        // dumps racing for the same artifact name, and retention counting the duplicates.
        if ($this->live->shouldSkipCron('BackupTask')) {
            return;
        }

        try {
            if (!$this->backups->isDue()) {
                return;
            }
            $runs = ['Database' => $this->backups->run('cron')];
            // Uploaded files too: the dump carries only the rows that point at them.
            $runs['Files'] = $this->files->run('cron');

            $failures = [];
            foreach ($runs as $label => $run) {
                if ($run->Status !== 'success') {
                    $failures[] = ['label' => $label, 'error' => $run->Error ?? 'unknown error'];
                    // Loudly, and to the journal: a failed backup that nobody notices is
                    // indistinguishable from no backups at all, right up until a restore is needed.
                    echo '[BackupTask] ' . date('Y-m-d H:i:s') . ' BACKUP FAILED (' . $label . '): '
                        . ($run->Error ?? 'unknown error') . PHP_EOL;
                }
            }
            $this->alert($failures);
        } catch (Throwable $e) {
            // BackupService::run() already catches its own failures; this is the belt-and-braces
            // for anything thrown before it takes over (settings, DI, the due check).
            echo '[BackupTask] ' . date('Y-m-d H:i:s') . ' FAILED before the run started: '
                . $e->getMessage() . PHP_EOL;
            $this->alert([['label' => 'Backup job', 'error' => $e->getMessage()]]);
        }
    }

    /** @param array $failures list of ['label','error']; empty means the run was clean */
    private function alert(array $failures): void
    {
        try {
            if (empty($failures)) {
                $this->cache->set(self::ALERT_STATE_KEY, json_encode(['failing' => false, 'lastSentAt' => 0]));
                return;
            }
            if (!$this->settings->getBool('backup.alertsEnabled', true)) {
                return;
            }
            $raw = $this->cache->get(self::ALERT_STATE_KEY);
            $state = is_string($raw) ? json_decode($raw, true) : null;
            $failing = is_array($state) && ($state['failing'] ?? false);
            $lastSentAt = is_array($state) ? (int) ($state['lastSentAt'] ?? 0) : 0;
            $cooldown = max(0, $this->settings->getInt('monitoring.alertCooldownMinutes', 60)) * 60;
            if ($failing && time() - $lastSentAt < $cooldown) {
                return;
            }
            $count = 0;
            foreach ($this->config->values['admins'] ?? [] as $admin) {
                $email = $admin['Email'] ?? null;
                if ($email) {
                    $this->mailer->dispatchBackupAlert($email, $failures);
                    $count++;
                }
            }
            $this->cache->set(self::ALERT_STATE_KEY, json_encode(['failing' => true, 'lastSentAt' => time()]));
            echo '[BackupTask] ' . date('Y-m-d H:i:s') . " sent {$count} backup alert(s)." . PHP_EOL;
        } catch (Throwable $e) {
            // The alert must never be the thing that breaks the backup tick.
            echo '[BackupTask] ' . date('Y-m-d H:i:s') . ' alert not sent: ' . $e->getMessage() . PHP_EOL;
        }
    }
}
