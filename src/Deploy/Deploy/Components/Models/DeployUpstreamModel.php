<?php

namespace SaasPaws\Deploy\Deploy\Components\Models;

use SharedPaws\Models\BaseModel;

/** One nginx upstream block of an application, as a row in the Upstreams table. */
class DeployUpstreamModel extends BaseModel
{
    public string $Name = '';
    /** The location it serves, for the operator's benefit: '/', '/api'. */
    public string $Location = '/';
    /** Blank derives the path `php fluffy nginx` writes — shown as `ConfPathResolved`. */
    public ?string $ConfPath = null;
    /** Read-only: where the block actually lives, derived when ConfPath is blank. */
    public string $ConfPathResolved = '';
    public int $DisplayOrder = 0;
}
