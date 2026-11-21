<?php

namespace SaasPaws\Ops;

/**
 * Capability bits of the Ops module (SaasPaws): log viewer, system monitor, cron admin.
 *
 * Bit numbers are Urlicer's, in the application's capability region (32..62). A user's
 * capabilities are stored as one integer bitmask (User.Permissions), so moving ViewSystemInfo from
 * the app's AppCapability into this class kept every existing grant. All three are granted to NO
 * role: SuperAdmin only, via the god short-circuit in Permissions::effective (as in Urlicer).
 */
final class OpsCapability
{
    public const ViewSystemInfo = 1 << 45; // /admin/system and /admin/logs
    public const ViewCron = 1 << 46;       // /admin/cron: job list and health
    public const ManageCron = 1 << 47;     // pause / resume all cron jobs
}
