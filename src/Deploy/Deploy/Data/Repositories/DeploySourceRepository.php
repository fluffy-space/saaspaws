<?php

namespace SaasPaws\Deploy\Deploy\Data\Repositories;

use SaasPaws\Deploy\Deploy\Data\Entities\DeploySourceEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeploySourceEntityMap;
use DotDi\Attributes\Inject;
use Fluffy\Data\Repositories\BasePostgresqlRepository;

/** @extends BasePostgresqlRepository<DeploySourceEntity> */
#[Inject(['entityType' => DeploySourceEntity::class, 'entityMap' => DeploySourceEntityMap::class])]
class DeploySourceRepository extends BasePostgresqlRepository
{
}
