<?php

namespace SaasPaws\Deploy\Deploy\Components\Models;

use SharedPaws\Models\BaseModel;

/**
 * One systemd unit of an application, as a row in the Services table.
 *
 * The upstream is carried by NAME, not by id: the rows are edited (and generated) before the
 * application has been saved, so an upstream the operator just added on the same form has no id
 * yet. The controller resolves the name against the application's own upstreams when it writes.
 */
class DeployServiceModel extends BaseModel
{
    public string $Name = '';
    /** blue | green | '' for a unit that does not rotate (a worker). */
    public ?string $Slot = null;
    public int $Port = 0;
    /** The upstream this unit answers; blank for a non-HTTP worker. */
    public ?string $UpstreamName = null;
    public int $DisplayOrder = 0;
}
