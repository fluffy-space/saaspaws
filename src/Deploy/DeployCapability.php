<?php

namespace SaasPaws\Deploy;

/**
 * Capability bits of the Deploy module (SaasPaws).
 *
 * Bit numbers are Urlicer's (Application\Security\AppCapability there), in the application's
 * capability region (32..62). A user's capabilities are stored as one integer bitmask
 * (User.Permissions), so moving a bit from the app's class into a module keeps every existing
 * grant: only the PHP constant moved.
 */
final class DeployCapability
{
    // Ships code to a production box. Granted to NO role: SuperAdmin only, via the god
    // short-circuit in Permissions::effective.
    public const ManageDeployments = 1 << 54; // configure applications/servers/sources, deploy, restart, push the vault
}
