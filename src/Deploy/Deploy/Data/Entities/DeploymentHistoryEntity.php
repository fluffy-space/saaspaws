<?php

namespace SaasPaws\Deploy\Deploy\Data\Entities;

use Fluffy\Data\Entities\BaseEntity;

/** One deploy attempt against one server — server-admin's DeploymentHistoryEntity + rotation. */
class DeploymentHistoryEntity extends BaseEntity
{
    public int $ApplicationId;
    public int $ServerId;
    public ?DeployServerEntity $Server = null;
    public ?int $SourceId = null;
    public ?DeploySourceEntity $Source = null;

    /** deploy | rollback | vault */
    public string $Kind = 'deploy';

    public ?int $StartedOn = null;
    public ?int $CompletedOn = null;
    /** Null while running — distinct from false, which means it ran and failed. */
    public ?bool $IsSuccessful = null;
    public ?string $Error = null;
    public ?string $Logs = null;

    // rotation detail
    public ?string $FromSlot = null;
    public ?string $ToSlot = null;
    public ?string $CommitSha = null;
    public ?string $CommitMessage = null;
    public ?string $TriggeredByName = null;
}
