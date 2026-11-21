<?php

namespace SaasPaws\Backup\Backups\Data\Entities;

use Fluffy\Data\Entities\BaseEntity;

/**
 * One backup attempt, successful or not (copied from Urlicer, whose docs/backup-plan.md §v2 has the design).
 *
 * This table is the answer to "do we actually have backups?" — the question that matters and the
 * one nobody asks until an incident. A job that quietly stops looks identical to a healthy one
 * from the outside, so every attempt writes a row: the failures are the point, not an afterthought.
 *
 * It is also what the admin page reads, and what a future absence check compares against.
 */
class BackupRun extends BaseEntity
{
    /** backup (something was written) | verify (something was read back and checked). */
    public string $Kind = 'backup';
    /** running | success | failed. */
    public string $Status = 'running';
    /** cron | manual — plus the actor for manual runs, so "who ran this" survives. */
    public string $Trigger = 'cron';
    /** Remote artifact name, e.g. myapp-pg-20260812T020000Z.tar.age (prefix = the database name). Null until uploaded. */
    public ?string $Artifact = null;
    /** Size of the uploaded artifact, verified against the remote byte count. */
    public int $SizeBytes = 0;
    /** Tables carrying data, per pg_restore --list. Zero is a failed verification, never a pass. */
    public int $TablesWithData = 0;
    /** Wall-clock duration of the whole run. */
    public int $DurationMs = 0;
    /** Whether the artifact was encrypted (an age recipient was configured at the time). */
    public bool $Encrypted = false;
    /** Failure reason, trimmed. Null on success. */
    public ?string $Error = null;
}
