<?php

namespace SaasPaws\Backup;

use Pupils\FluffyPupils;
use Viewi\Packages\ViewiPackage;

/**
 * The Backup module's Viewi components. Only the Components/ folders are listed: Viewi transpiles
 * every PHP file under a components path to JS, so controllers, services, tasks and entities stay out.
 */
class BackupViewiPackage extends ViewiPackage
{
    public static function getComponentsPath(): array
    {
        return [__DIR__ . DIRECTORY_SEPARATOR . 'Backups' . DIRECTORY_SEPARATOR . 'Components'];
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
        return 'saaspaws-backup';
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
