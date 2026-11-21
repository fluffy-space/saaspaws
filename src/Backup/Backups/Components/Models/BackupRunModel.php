<?php

namespace SaasPaws\Backup\Backups\Components\Models;

use SharedPaws\Models\BaseModel;

/**
 * One backup attempt, as the admin list sees it.
 *
 * Failures are first-class here, not an error case: a job that quietly stops looks exactly like a
 * healthy one from the outside, and this list is the only place that difference shows.
 */
class BackupRunModel extends BaseModel
{
    /** backup | verify. */
    public string $Kind = 'backup';
    /** running | success | failed. */
    public string $Status = 'running';
    /** cron | manual (email), so "who ran this" survives. */
    public string $Trigger = '';
    public ?string $Artifact = null;
    public int $SizeBytes = 0;
    public int $TablesWithData = 0;
    public int $DurationMs = 0;
    public bool $Encrypted = false;
    public ?string $Error = null;
}
