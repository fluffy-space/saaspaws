<?php

namespace SaasPaws\Ops\Monitoring\Components\Emails;

use Viewi\Components\BaseComponent;
use Viewi\Components\Config\ConfigService;

/**
 * Server-side-only weekly resource snapshot for admins (OpsStartUp puts this Emails
 * namespace in noJsNamespace, so it never ships to the browser). Driven by
 * ResourceReportTask off SystemService — a point-in-time snapshot at send
 * time (no metric history is stored), covering CPU / memory / disk and Redis.
 *
 * $_noBrowser components are never transpiled to JS, so plain-PHP helpers here
 * (number_format, byte formatting) are fine — no transpiler-subset limits apply.
 */
class ResourceWeeklyReportEmail extends BaseComponent
{
    public static bool $_noBrowser = true;

    public string $baseUrl = '/';
    /** @var array ['os','redis'] */
    public array $snapshot = [];
    public string $generatedOn = '';

    public function __construct(array $snapshot, ConfigService $configService)
    {
        $this->snapshot = $snapshot;
        $this->baseUrl = $configService->get('baseUrl');
        $this->generatedOn = gmdate('Y-m-d H:i') . ' UTC';
    }

    public function pct($value): string
    {
        return number_format((float)$value, 1) . '%';
    }

    public function formatBytes($bytes): string
    {
        $bytes = (float)$bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return number_format($bytes, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }
}
