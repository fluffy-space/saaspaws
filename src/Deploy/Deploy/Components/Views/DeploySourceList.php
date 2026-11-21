<?php

namespace SaasPaws\Deploy\Deploy\Components\Views;

use SaasPaws\Deploy\Deploy\Components\Models\DeploySourceModel;
use Pupils\Components\Guards\HasCapability;
use Viewi\Components\Attributes\Middleware;
use Viewi\Components\BaseComponent;
use Viewi\UI\Components\Tables\TableColumn;

/** /admin/deploy-source — where deployable code comes from. */
#[Middleware([[HasCapability::class, 'ManageDeployments']])]
class DeploySourceList extends BaseComponent
{
    public array $columns = [];

    public function init()
    {
        $this->columns = [
            new TableColumn('Id'),
            new TableColumn('Name'),
            new TableColumn('GitRepository', 'Repository'),
            new TableColumn('GitBranch', 'Branch'),
            new TableColumn('HasToken', 'Token'),
        ];
    }

    public function deleteMessage()
    {
        return fn(DeploySourceModel $item) => "Delete source {$item->Name}?";
    }
}
