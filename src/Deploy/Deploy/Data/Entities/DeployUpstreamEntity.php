<?php

namespace SaasPaws\Deploy\Deploy\Data\Entities;

use Fluffy\Data\Entities\BaseEntity;

/**
 * One nginx upstream block — server-admin had a single `Upstream` + `UpstreamPort`; an app can
 * have several, one per location (`/`, `/api`, …), each pointing at its own service port.
 *
 * The `server 127.0.0.1:<port>` line in ConfPath is the source of truth for which slot is live;
 * a rotation rewrites it. ConfPath blank derives the path `php fluffy nginx` writes.
 */
class DeployUpstreamEntity extends BaseEntity
{
    public int $ApplicationId;
    /** Upstream block name, e.g. myapp_app. */
    public string $Name;
    /** Location it serves, for the operator's benefit: '/', '/api'. */
    public ?string $Location = '/';
    /** Conf file holding the block; blank derives /etc/nginx/conf.d/10-upstream-<name>.conf. */
    public ?string $ConfPath = null;
    public int $DisplayOrder = 0;

    /**
     * Where the block actually lives.
     *
     * Derived rather than required: `php fluffy nginx` (NginxBuilder) owns this path, so asking
     * an operator to retype it only creates a way for the two to disagree — and a deploy pointed
     * at the wrong file rotates nothing while reporting success. An override stays available for
     * a box with a non-standard layout.
     */
    public function confPath(): string
    {
        $explicit = trim((string) $this->ConfPath);
        return $explicit !== '' ? $explicit : "/etc/nginx/conf.d/10-upstream-{$this->Name}.conf";
    }
}
