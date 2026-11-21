<?php

namespace SaasPaws\Backup\Backups\Data\Repositories;

use SaasPaws\Backup\Backups\Data\Entities\BackupRun;
use SaasPaws\Backup\Backups\Data\Entities\BackupRunMap;
use DotDi\Attributes\Inject;
use Fluffy\Data\Repositories\BasePostgresqlRepository;

/** @extends BasePostgresqlRepository<BackupRun> */
#[Inject(['entityType' => BackupRun::class, 'entityMap' => BackupRunMap::class])]
class BackupRunRepository extends BasePostgresqlRepository
{
}
