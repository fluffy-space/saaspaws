<?php

namespace SaasPaws\Deploy\Deploy\Data\Repositories;

use SaasPaws\Deploy\Deploy\Data\Entities\DeployApplicationEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployApplicationEntityMap;
use DotDi\Attributes\Inject;
use Fluffy\Data\Repositories\BasePostgresqlRepository;

/** @extends BasePostgresqlRepository<DeployApplicationEntity> */
#[Inject(['entityType' => DeployApplicationEntity::class, 'entityMap' => DeployApplicationEntityMap::class])]
class DeployApplicationRepository extends BasePostgresqlRepository
{
}
