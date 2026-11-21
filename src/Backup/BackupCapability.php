<?php

namespace SaasPaws\Backup;

/**
 * Capability bits of the Backup module (SaasPaws). Bit numbers are Urlicer's, in the application's
 * capability region (32..62); a user's capabilities are one integer bitmask (User.Permissions).
 * Granted to NO role: SuperAdmin only, via the god short-circuit in Permissions::effective. This
 * surface reports on, and can trigger, moving the entire database off the box.
 */
final class BackupCapability
{
    public const ViewBackups = 1 << 55;   // /admin/backups: history, status, what is on the Storage Box
    public const ManageBackups = 1 << 56; // run a backup / verify / files backup now
}
