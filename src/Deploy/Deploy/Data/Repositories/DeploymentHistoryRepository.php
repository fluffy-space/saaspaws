<?php

namespace SaasPaws\Deploy\Deploy\Data\Repositories;

use SaasPaws\Deploy\Deploy\Data\Entities\DeploymentHistoryEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeploymentHistoryEntityMap;
use DotDi\Attributes\Inject;
use Fluffy\Data\Repositories\BasePostgresqlRepository;

/** @extends BasePostgresqlRepository<DeploymentHistoryEntity> */
#[Inject(['entityType' => DeploymentHistoryEntity::class, 'entityMap' => DeploymentHistoryEntityMap::class])]
class DeploymentHistoryRepository extends BasePostgresqlRepository
{
}
