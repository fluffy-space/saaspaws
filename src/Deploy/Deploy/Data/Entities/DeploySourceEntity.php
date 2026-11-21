<?php

namespace SaasPaws\Deploy\Deploy\Data\Entities;

use Fluffy\Data\Entities\BaseEntity;

/** Where the code comes from — server-admin's SourceCodeEntity. */
class DeploySourceEntity extends BaseEntity
{
    public string $Name;
    public string $Type = 'git';
    public ?string $Description = null;
    public ?string $GitRepository = null;
    public ?string $GitUser = null;
    /** PAT — write-only, scrubbed out of deploy logs. */
    public ?string $GitAccessToken = null;
    public ?string $GitBranch = 'main';
    /** Newline-separated build commands; blank uses the project default. */
    public ?string $BuildCommand = null;
}
