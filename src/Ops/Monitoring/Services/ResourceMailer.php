<?php

namespace SaasPaws\Ops\Monitoring\Services;

use Fluffy\Domain\Configuration\Config;
use SaasPaws\Ops\Monitoring\Components\Emails\ResourceAlertEmail;
use SaasPaws\Ops\Monitoring\Components\Emails\ResourceWeeklyReportEmail;
use Fluffy\Swoole\Task\TaskManager;
use FluffyPaws\Services\Emails\EmailLogService;
use Viewi\App;

/**
 * Sends the resource-monitoring emails. Bodies are rendered from the server-only
 * ResourceAlertEmail / ResourceWeeklyReportEmail components and delivered off the
 * Swoole task pool via EmailLogService (records an EmailLog row per admin).
 *
 * Called by ResourceAlertTask (high-usage alert, cooldown + recovery) and
 * ResourceReportTask (weekly snapshot). The dispatch* methods push a
 * first-class method reference onto the task pool — TaskManager serializes the
 * closure via reflection, so it must be `$this->send*(...)`, not an inline closure.
 *
 * Subjects start with "[<email.from_name>]" from config (the app's name), not a fixed brand.
 */
class ResourceMailer
{
    public function __construct(
        private TaskManager $tasks,
        private App $viewiApp,
        private EmailLogService $emailLog,
        private Config $config,
    ) {}

    /** "[My App] " from config email.from_name; empty when it is not set. */
    private function prefix(): string
    {
        $name = trim((string) ($this->config->values['email']['from_name'] ?? ''));
        return $name !== '' ? "[$name] " : '';
    }

    /** @param array $breaches list of ['label','value','threshold','unit'] */
    public function dispatchAlert(string $email, array $breaches): void
    {
        $this->tasks->dispatch($this->sendAlert(...), $email, $breaches);
    }

    public function sendAlert(string $email, array $breaches): void
    {
        $html = $this->viewiApp->engine()->render(
            ResourceAlertEmail::class,
            ['breaches' => $breaches]
        );
        $summary = implode(', ', array_map(
            fn($b) => "{$b['label']} " . round((float)$b['value']) . ($b['unit'] ?? ''),
            $breaches
        ));
        $subject = $this->prefix() . "Resource alert: {$summary}";
        $altBody = "Resource alert — over threshold: {$summary}. See /admin/system.";
        $this->emailLog->send('resource-alert', $email, $subject, $html->body, '', $altBody);
    }

    /** @param array $snapshot ['os','redis'] */
    public function dispatchWeeklyReport(string $email, array $snapshot): void
    {
        $this->tasks->dispatch($this->sendWeeklyReport(...), $email, $snapshot);
    }

    public function sendWeeklyReport(string $email, array $snapshot): void
    {
        $html = $this->viewiApp->engine()->render(
            ResourceWeeklyReportEmail::class,
            ['snapshot' => $snapshot]
        );
        $subject = $this->prefix() . 'Weekly resource report';
        $os = $snapshot['os'] ?? [];
        $altBody = 'Weekly resource report — CPU ' . round((float)($os['cpu_load'] ?? 0)) . '%, '
            . 'memory ' . round((float)($os['used_percentage'] ?? 0)) . '%, '
            . 'disk ' . ($os['disk_space_used_precentage'] ?? '?') . ' used. See /admin/system.';
        $this->emailLog->send('resource-report', $email, $subject, $html->body, '', $altBody);
    }
}
