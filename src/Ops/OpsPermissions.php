<?php

namespace SaasPaws\Ops;

use Fluffy\Security\PermissionRegistry;

/** Names the Ops module's capability bits (for the client: HasCapability, session.capabilities). */
final class OpsPermissions
{
    public static function register(): void
    {
        PermissionRegistry::defineCapability(OpsCapability::ViewSystemInfo, 'ViewSystemInfo');
        PermissionRegistry::defineCapability(OpsCapability::ViewCron, 'ViewCron');
        PermissionRegistry::defineCapability(OpsCapability::ManageCron, 'ManageCron');
    }
}
