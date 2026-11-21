<?php

namespace SaasPaws\Deploy\Deploy\Data\Repositories;

use SaasPaws\Deploy\Deploy\Data\Entities\DeployServiceEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployServiceEntityMap;
use DotDi\Attributes\Inject;
use Fluffy\Data\Repositories\BasePostgresqlRepository;

/** @extends BasePostgresqlRepository<DeployServiceEntity> */
#[Inject(['entityType' => DeployServiceEntity::class, 'entityMap' => DeployServiceEntityMap::class])]
class DeployServiceRepository extends BasePostgresqlRepository
{
}
