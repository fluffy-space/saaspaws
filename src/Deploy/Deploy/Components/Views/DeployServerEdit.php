<?php

namespace SaasPaws\Deploy\Deploy\Components\Views;

use SaasPaws\Deploy\Deploy\Components\Models\DeployServerModel;
use SaasPaws\Deploy\Deploy\Components\Models\DeployServerValidation;
use Pupils\Components\Guards\HasCapability;
use Pupils\Components\Views\Admin\EditPage\EditPage;
use SharedPaws\Models\BaseModel;
use SharedPaws\Validation\IValidationRules;
use Viewi\Components\Attributes\Middleware;

/**
 * @property DeployServerModel $item
 */
#[Middleware([[HasCapability::class, 'ManageDeployments']])]
class DeployServerEdit extends EditPage
{
    public string $segment = 'deploy-server';
    public string $name = "Server";

    public function getValidation(BaseModel $item): ?IValidationRules
    {
        return new DeployServerValidation($item);
    }

    public function getNewItem(): BaseModel
    {
        return new DeployServerModel();
    }
}
