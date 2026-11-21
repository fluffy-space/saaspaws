<?php

namespace SaasPaws\Backup;

use Fluffy\Security\PermissionRegistry;

/** Names the Backup module's capability bits (for the client: HasCapability, session.capabilities). */
final class BackupPermissions
{
    public static function register(): void
    {
        PermissionRegistry::defineCapability(BackupCapability::ViewBackups, 'ViewBackups');
        PermissionRegistry::defineCapability(BackupCapability::ManageBackups, 'ManageBackups');
    }
}
