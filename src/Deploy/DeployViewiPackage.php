<?php

namespace SaasPaws\Deploy;

use Pupils\FluffyPupils;
use Viewi\Packages\ViewiPackage;

/**
 * The Deploy module's Viewi components. Only the Components/ folders are listed: Viewi transpiles
 * every PHP file under a components path to JS, so controllers, services and entities stay out.
 */
class DeployViewiPackage extends ViewiPackage
{
    public static function getComponentsPath(): array
    {
        return [__DIR__ . DIRECTORY_SEPARATOR . 'Deploy' . DIRECTORY_SEPARATOR . 'Components'];
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
        return 'saaspaws-deploy';
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
