<?php

namespace SaasPaws\Deploy\Deploy\Data\Repositories;

use SaasPaws\Deploy\Deploy\Data\Entities\DeployServerEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployServerEntityMap;
use DotDi\Attributes\Inject;
use Fluffy\Data\Repositories\BasePostgresqlRepository;

/** @extends BasePostgresqlRepository<DeployServerEntity> */
#[Inject(['entityType' => DeployServerEntity::class, 'entityMap' => DeployServerEntityMap::class])]
class DeployServerRepository extends BasePostgresqlRepository
{
}
