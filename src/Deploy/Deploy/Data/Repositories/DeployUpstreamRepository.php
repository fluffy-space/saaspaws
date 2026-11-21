<?php

namespace SaasPaws\Deploy\Deploy\Data\Repositories;

use SaasPaws\Deploy\Deploy\Data\Entities\DeployUpstreamEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployUpstreamEntityMap;
use DotDi\Attributes\Inject;
use Fluffy\Data\Repositories\BasePostgresqlRepository;

/** @extends BasePostgresqlRepository<DeployUpstreamEntity> */
#[Inject(['entityType' => DeployUpstreamEntity::class, 'entityMap' => DeployUpstreamEntityMap::class])]
class DeployUpstreamRepository extends BasePostgresqlRepository
{
}
