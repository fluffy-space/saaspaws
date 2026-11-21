<?php

namespace SaasPaws\Deploy\Deploy\Data\Entities;

use Fluffy\Data\Entities\BaseEntity;

/** Application <-> Server join, as in server-admin. */
class DeployApplicationServerEntity extends BaseEntity
{
    public int $ApplicationId;
    public ?DeployApplicationEntity $Application = null;
    public int $ServerId;
    public ?DeployServerEntity $Server = null;
}
