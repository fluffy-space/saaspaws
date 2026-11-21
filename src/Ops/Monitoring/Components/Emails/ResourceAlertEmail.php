<?php

namespace SaasPaws\Ops\Monitoring\Components\Emails;

use Viewi\Components\BaseComponent;
use Viewi\Components\Config\ConfigService;

/**
 * Server-side-only email warning admins that a host resource (CPU / memory / disk)
 * crossed its alert threshold (OpsStartUp puts this Emails namespace in noJsNamespace,
 * so it never ships to the browser). Driven by ResourceAlertTask off the live
 * SystemService metrics; carries the breached metrics and a link to /admin/system.
 *
 * $_noBrowser components are never transpiled to JS, so plain-PHP helpers here
 * (round/format) are fine — no transpiler-subset limits apply.
 */
class ResourceAlertEmail extends BaseComponent
{
    public static bool $_noBrowser = true;

    public string $baseUrl = '/';
    /** @var array list of ['label','value','threshold','unit'] */
    public array $breaches = [];

    public function __construct(array $breaches, ConfigService $configService)
    {
        $this->breaches = $breaches;
        $this->baseUrl = $configService->get('baseUrl');
    }

    public function fmt($value): string
    {
        return (string)round((float)$value, 1);
    }
}
