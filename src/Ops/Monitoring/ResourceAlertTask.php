<?php

namespace SaasPaws\Ops\Monitoring;

use SaasPaws\Deploy\LiveInstance\LiveInstanceService;
use SaasPaws\Ops\Monitoring\Services\ResourceMailer;
use SaasPaws\Ops\System\Services\SystemService;
use Fluffy\Domain\Configuration\Config;
use Fluffy\Services\Cache\RedisCache;
use Fluffy\Services\Settings\SettingsService;

/**
 * At the feature root, not in a Tasks\ sub-namespace: Fluffy's cron keys ("<uid>|<class>|<method>"
 * in a Swoole\Table) must stay under 64 bytes, and the longer name ran over.
 *
 * Watches host resources and emails the admin list when CPU / memory / disk cross
 * their configured thresholds. Runs every 5 min in the Swoole task-worker scope
 * (fresh DI scope per run), reading only the cheap SystemService::getOsInfo().
 *
 * Alerting is cooldown + recovery (per metric, independently):
 *   - normal → over threshold: send once, mark over.
 *   - stays over: silent until `monitoring.alertCooldownMinutes` elapses, then a
 *     single still-high reminder (and again each cooldown while stuck).
 *   - drops back below threshold: re-arm, so the next crossing alerts again.
 * State is transient runtime bookkeeping, so it lives in the Redis cache (key
 * `monitoring.alertState`), not the settings store. All metrics that fire in a
 * given run are batched into one email. No-op (silent) when alerts are disabled or
 * nothing is due.
 */
class ResourceAlertTask
{
    public function __construct(
        private SystemService $system,
        private ResourceMailer $mailer,
        private SettingsService $settings,
        private RedisCache $cache,
        private Config $config,
        private LiveInstanceService $live,
    ) {}

    private const STATE_KEY = 'monitoring.alertState';

    public function execute()
    {
        // Blue/green: only the colour taking traffic runs cron, or every job fires
        // twice while both instances are up (see LiveInstanceService).
        if ($this->live->shouldSkipCron('ResourceAlertTask')) {
            return;
        }

        if (!$this->settings->getBool('monitoring.alertsEnabled', false)) {
            return;
        }

        $os = $this->system->getOsInfo();
        $cooldown = max(0, $this->settings->getInt('monitoring.alertCooldownMinutes', 60)) * 60;
        $now = time();

        // metricKey => [label, current value, threshold, unit]
        $metrics = [
            'cpu'  => ['CPU',    (float)($os['cpu_load'] ?? 0),        (float)$this->settings->getInt('monitoring.cpuThreshold', 90),  '%'],
            'mem'  => ['Memory', (float)($os['used_percentage'] ?? 0), (float)$this->settings->getInt('monitoring.memThreshold', 90),  '%'],
            'disk' => ['Disk',   (float)($os['disk_space_used_precentage'] ?? 0), (float)$this->settings->getInt('monitoring.diskThreshold', 90), '%'],
            // CPU steal — time the hypervisor gave to other tenants while we had work ready.
            // Unlike the others this is not a capacity signal but a *neighbours* signal: it is
            // the evidence for moving off shared vCPU to the dedicated line, and nothing else
            // surfaces it (a busy box and a starved box have the same load average). Threshold
            // defaults to 5%, the point at which it stops being background noise.
            'steal' => ['CPU steal', (float)($os['cpu_steal'] ?? 0), (float)$this->settings->getInt('monitoring.stealThreshold', 5), '%'],
            // Redis against its OWN maxmemory cap, not host RAM (the 'mem' metric covers that):
            // with noeviction, writes stop at the cap while the host still looks half idle. Lower
            // threshold (80%) on purpose: raising the cap needs lead time. Reads 0 while Redis is
            // uncapped (maxmemory 0), so it never fires there.
            'redis' => ['Redis', $this->redisFillPercent(), (float)$this->settings->getInt('monitoring.redisThreshold', 80), '% of cap'],
        ];

        // RedisCache decodes to stdClass, so we store/read the state as a JSON string
        // and decode it back to associative arrays ourselves.
        $raw = $this->cache->get(self::STATE_KEY);
        $state = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($state)) {
            $state = [];
        }

        $breaches = [];
        foreach ($metrics as $key => [$label, $value, $threshold, $unit]) {
            $entry = is_array($state[$key] ?? null) ? $state[$key] : ['over' => false, 'lastSentAt' => 0];
            $over = $value >= $threshold;

            if ($over) {
                $wasOver = (bool)($entry['over'] ?? false);
                $lastSentAt = (int)($entry['lastSentAt'] ?? 0);
                // Fire on the normal→over crossing, or (while stuck over) once per cooldown window.
                $fire = !$wasOver || ($now - $lastSentAt >= $cooldown);
                if ($fire) {
                    $breaches[] = ['label' => $label, 'value' => $value, 'threshold' => $threshold, 'unit' => $unit];
                    $entry['lastSentAt'] = $now;
                }
                $entry['over'] = true;
            } else {
                $entry['over'] = false; // recovered — re-arm
            }
            $state[$key] = $entry;
        }

        $this->emit($breaches, $state);
    }

    /**
     * How full the app's Redis is against its own cap, as a percentage.
     * 0 when uncapped or unreachable — an unreachable Redis is a louder failure than this
     * task, and reporting a fake 0 is better than an alert storm about a metric we lack.
     */
    private function redisFillPercent(): float
    {
        try {
            return (float) ($this->system->getRedisInfoForApp()['maxmemory_percentage'] ?? 0);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /** @param array $breaches metrics that crossed this run @param array $state cooldown bookkeeping */
    private function emit(array $breaches, array $state): void
    {
        if (!empty($breaches)) {
            $count = 0;
            foreach ($this->config->values['admins'] ?? [] as $admin) {
                $email = $admin['Email'] ?? null;
                if ($email) {
                    $this->mailer->dispatchAlert($email, $breaches);
                    $count++;
                }
            }
            $names = implode('/', array_map(fn($b) => $b['label'], $breaches));
            echo '[ResourceAlertTask] ' . date('Y-m-d H:i:s')
                . " sent {$count} alert(s) for {$names}." . PHP_EOL;
        }

        $this->cache->set(self::STATE_KEY, json_encode($state));
    }
}
