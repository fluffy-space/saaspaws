<?php

namespace SaasPaws\Deploy\Deploy\Data\Entities;

use Fluffy\Data\Entities\BaseEntity;

/**
 * One systemd unit belonging to an application — the extension over server-admin's single
 * `ServiceName`.
 *
 * `Slot` is what makes rotation work: under `rotate` an app has the same service twice
 * (myapp-blue:8221, myapp-green:8222) and a deploy builds into whichever slot is idle.
 * A service with no slot (a worker, a queue consumer) is simply restarted.
 *
 * `UpstreamId` says which upstream location this service answers, so an app can serve `/` from
 * one service and `/api` from another and each gets repointed to its own new port.
 */
class DeployServiceEntity extends BaseEntity
{
    public int $ApplicationId;
    /** systemd unit name, e.g. myapp-blue. */
    public string $Name;
    /** blue | green | '' for a service that does not rotate. */
    public ?string $Slot = null;
    /** Port this unit listens on. */
    public int $Port = 0;
    /** The upstream location this service answers; null for a non-HTTP worker. */
    public ?int $UpstreamId = null;
    public int $DisplayOrder = 0;
}
