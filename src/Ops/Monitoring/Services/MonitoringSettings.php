<?php

namespace SaasPaws\Ops\Monitoring\Services;

use Fluffy\Services\Settings\SettingDefinition;
use Fluffy\Services\Settings\SettingsRegistry;

/**
 * Declares the resource-monitoring settings (Fluffy settings store) so thresholds
 * and toggles are editable live on the admin settings page (group `monitoring`)
 * without a redeploy. Drives ResourceAlertTask (high-usage alerts, cooldown +
 * recovery) and ResourceReportTask (weekly snapshot).
 *
 * Called once per worker at boot from OpsStartUp::configureServices (SettingsRegistry
 * is static / in-memory — no DB). The alert cron's transient per-metric state is
 * kept in the Redis cache (key `monitoring.alertState`), not here.
 */
class MonitoringSettings
{
    public static function register(): void
    {
        SettingsRegistry::define(new SettingDefinition(
            key: 'monitoring.alertsEnabled',
            type: 'boolean',
            group: 'monitoring',
            label: 'Resource alerts enabled',
            description: 'Email the admin list when CPU / memory / disk cross their thresholds.',
            // On by default. When two apps share one box, turn it off in all but one of them:
            // each would report the same host and send the same email.
            initial: true,
        ));
        SettingsRegistry::define(new SettingDefinition(
            key: 'monitoring.cpuThreshold',
            type: 'number',
            group: 'monitoring',
            label: 'CPU alert threshold (%)',
            description: 'Alert when CPU load is at or above this percentage.',
            initial: 90,
        ));
        SettingsRegistry::define(new SettingDefinition(
            key: 'monitoring.memThreshold',
            type: 'number',
            group: 'monitoring',
            label: 'Memory alert threshold (%)',
            description: 'Alert when used memory is at or above this percentage.',
            initial: 90,
        ));
        SettingsRegistry::define(new SettingDefinition(
            key: 'monitoring.diskThreshold',
            type: 'number',
            group: 'monitoring',
            label: 'Disk alert threshold (%)',
            description: 'Alert when the root filesystem is at or above this percentage full.',
            initial: 90,
        ));
        SettingsRegistry::define(new SettingDefinition(
            key: 'monitoring.stealThreshold',
            type: 'number',
            group: 'monitoring',
            label: 'CPU steal alert threshold (%)',
            description: 'Alert when CPU steal — time the hypervisor gave to other tenants while '
                . 'we had work ready — is at or above this percentage. Sustained steal is the '
                . 'evidence for moving from shared vCPU to a dedicated instance; below ~5% it is '
                . 'normal background noise on any shared host.',
            initial: 5,
        ));
        SettingsRegistry::define(new SettingDefinition(
            key: 'monitoring.redisThreshold',
            type: 'number',
            group: 'monitoring',
            label: 'Redis alert threshold (% of cap)',
            description: 'Alert when Redis reaches this share of its own maxmemory cap. Measured '
                . 'against the cap, not host RAM: with noeviction, writes stop at the cap while the '
                . 'host still looks idle. Set lower than the other thresholds because raising the cap '
                . 'needs lead time. Never fires while Redis has no cap (maxmemory 0).',
            initial: 80,
        ));
        SettingsRegistry::define(new SettingDefinition(
            key: 'monitoring.alertCooldownMinutes',
            type: 'number',
            group: 'monitoring',
            label: 'Alert cooldown (minutes)',
            description: 'While a metric stays over threshold, wait this long before re-sending a reminder. Recovery (dropping back to normal) re-arms the alert immediately.',
            initial: 60,
        ));
        SettingsRegistry::define(new SettingDefinition(
            key: 'monitoring.weeklyReportEnabled',
            type: 'boolean',
            group: 'monitoring',
            label: 'Weekly resource report enabled',
            description: 'Email a resource snapshot to the admin list every Monday morning.',
            // On by default. When two apps share one box, turn it off in all but one of them:
            // each would report the same host and send the same email.
            initial: true,
        ));
    }
}
