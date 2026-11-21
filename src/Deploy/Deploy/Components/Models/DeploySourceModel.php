<?php

namespace SaasPaws\Deploy\Deploy\Components\Models;

use SharedPaws\Models\BaseModel;

/**
 * Where deployable code comes from. `GitAccessToken` is write-only (see DeployServerModel);
 * `HasToken` says whether one is stored.
 */
class DeploySourceModel extends BaseModel
{
    public string $Name = '';
    public string $Type = 'git';
    public ?string $Description = null;
    public ?string $GitRepository = null;
    public ?string $GitUser = null;
    public ?string $GitAccessToken = null;
    public ?string $GitBranch = 'main';
    /** One command per line. Blank uses this project's default build. */
    public ?string $BuildCommand = null;
    public bool $HasToken = false;
}
