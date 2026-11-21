<?php

namespace SaasPaws\Deploy\Deploy\Data\Repositories;

use SaasPaws\Deploy\Deploy\Data\Entities\DeployApplicationServerEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployApplicationServerEntityMap;
use DotDi\Attributes\Inject;
use Fluffy\Data\Repositories\BasePostgresqlRepository;

/** @extends BasePostgresqlRepository<DeployApplicationServerEntity> */
#[Inject(['entityType' => DeployApplicationServerEntity::class, 'entityMap' => DeployApplicationServerEntityMap::class])]
class DeployApplicationServerRepository extends BasePostgresqlRepository
{
    /**
     * The links of one application with their `Server` navigation filled in — every deploy
     * iterates these, so resolving the servers belongs here rather than in each caller.
     *
     * @return DeployApplicationServerEntity[]
     */
    public function getApplicationServers(int $applicationId, DeployServerRepository $servers): array
    {
        $links = $this->search(
            [[DeployApplicationServerEntityMap::PROPERTY_ApplicationId, $applicationId]],
            [DeployApplicationServerEntityMap::PROPERTY_Id => 1],
            1,
            null,
            false
        );
        $this->include(
            $links['list'],
            $servers,
            DeployApplicationServerEntityMap::PROPERTY_ServerId,
            DeployApplicationServerEntityMap::PROPERTY_Server
        );
        return $links['list'];
    }
}
