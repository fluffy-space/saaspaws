<?php

namespace SaasPaws\Deploy;

use Fluffy\Security\PermissionRegistry;

/** Names the Deploy module's capability bits (for the client: HasCapability, session.capabilities). */
final class DeployPermissions
{
    public static function register(): void
    {
        PermissionRegistry::defineCapability(DeployCapability::ManageDeployments, 'ManageDeployments');
    }
}
