<?php

namespace SaasPaws\Ops\Monitoring\Services;

use FluffyPaws\Services\Emails\EmailPreviewContext;
use FluffyPaws\Services\Emails\EmailPreviewTemplate;
use FluffyPaws\Services\Emails\IEmailPreviewProvider;
use SaasPaws\Ops\Monitoring\Components\Emails\ResourceAlertEmail;
use SaasPaws\Ops\Monitoring\Components\Emails\ResourceWeeklyReportEmail;

/**
 * Contributes the resource-monitoring email templates (high-usage alert + weekly report) to the
 * admin email-preview page (/admin/email-templates), rendered with sample data.
 */
class OpsEmailPreviewProvider implements IEmailPreviewProvider
{
    public function templates(): array
    {
        return [
            new EmailPreviewTemplate(
                'resource-alert',
                'Resource alert',
                fn(EmailPreviewContext $ctx) => $ctx->viewiApp->engine()->render(
                    ResourceAlertEmail::class,
                    ['breaches' => [
                        ['label' => 'CPU', 'value' => 92.4, 'threshold' => 90, 'unit' => '%'],
                        ['label' => 'Disk', 'value' => 94, 'threshold' => 90, 'unit' => '%'],
                    ]]
                )
            ),
            new EmailPreviewTemplate(
                'resource-report',
                'Weekly resource report',
                fn(EmailPreviewContext $ctx) => $ctx->viewiApp->engine()->render(
                    ResourceWeeklyReportEmail::class,
                    ['snapshot' => [
                        'os' => [
                            'cpu_load' => 14.2,
                            'used_percentage' => 61.8,
                            'mem_used' => 10_200_000_000,
                            'mem_total' => 16_500_000_000,
                            'disk_space_used_precentage' => '68%',
                            'disk_space_used' => '12G',
                            'disk_space_total' => '40G',
                            'disk_space_free' => '28G',
                        ],
                        'redis' => ['used_memory_human' => '24.1M', 'keys' => 1843, 'db_index' => 1],
                    ]]
                )
            ),
        ];
    }
}
