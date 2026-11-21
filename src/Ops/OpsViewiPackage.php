<?php

namespace SaasPaws\Ops;

use Pupils\FluffyPupils;
use Viewi\Packages\ViewiPackage;

/**
 * The Ops module's Viewi components. Only the Components/ folders are listed: Viewi transpiles
 * every PHP file under a components path to JS, so controllers, services and tasks stay out.
 */
class OpsViewiPackage extends ViewiPackage
{
    public static function getComponentsPath(): array
    {
        $d = DIRECTORY_SEPARATOR;
        return [
            __DIR__ . $d . 'Components',
            __DIR__ . $d . 'Logs' . $d . 'Components',
            __DIR__ . $d . 'System' . $d . 'Components',
            __DIR__ . $d . 'Cron' . $d . 'Components',
            __DIR__ . $d . 'Monitoring' . $d . 'Components',
        ];
    }

    public static function jsDir(): ?string
    {
        return null;
    }

    public static function jsModulePackagePath(): ?string
    {
        return null;
    }

    public static function name(): string
    {
        return 'saaspaws-ops';
    }

    public static function assetsPath(): ?string
    {
        return null;
    }

    public static function getDependencies(): array
    {
        return [FluffyPupils::class];
    }
}
