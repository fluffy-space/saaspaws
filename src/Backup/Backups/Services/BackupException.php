<?php

namespace SaasPaws\Backup\Backups\Services;

use RuntimeException;

/** A backup run failed a step or a verification gate. Caught by BackupService::run(). */
class BackupException extends RuntimeException
{
}
