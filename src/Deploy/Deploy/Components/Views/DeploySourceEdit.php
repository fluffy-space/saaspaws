<?php

namespace SaasPaws\Deploy\Deploy\Components\Views;

use SaasPaws\Deploy\Deploy\Components\Models\DeploySourceModel;
use SaasPaws\Deploy\Deploy\Components\Models\DeploySourceValidation;
use Pupils\Components\Guards\HasCapability;
use Pupils\Components\Views\Admin\EditPage\EditPage;
use SharedPaws\Models\BaseModel;
use SharedPaws\Validation\IValidationRules;
use Viewi\Components\Attributes\Middleware;

/**
 * @property DeploySourceModel $item
 */
#[Middleware([[HasCapability::class, 'ManageDeployments']])]
class DeploySourceEdit extends EditPage
{
    public string $segment = 'deploy-source';
    public string $name = "Source";

    public function getValidation(BaseModel $item): ?IValidationRules
    {
        return new DeploySourceValidation($item);
    }

    public function getNewItem(): BaseModel
    {
        return new DeploySourceModel();
    }
}
