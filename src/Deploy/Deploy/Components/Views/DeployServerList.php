<?php

namespace SaasPaws\Deploy\Deploy\Components\Views;

use SaasPaws\Deploy\Deploy\Components\Models\DeployServerModel;
use Pupils\Components\Guards\HasCapability;
use Viewi\Components\Attributes\Middleware;
use Viewi\Components\BaseComponent;
use Viewi\UI\Components\Tables\TableColumn;

/** /admin/deploy-server — the boxes we can deploy to. */
#[Middleware([[HasCapability::class, 'ManageDeployments']])]
class DeployServerList extends BaseComponent
{
    public array $columns = [];

    public function init()
    {
        $this->columns = [
            new TableColumn('Id'),
            new TableColumn('Name'),
            new TableColumn('Host'),
            new TableColumn('Port'),
            new TableColumn('UserName', 'User'),
            new TableColumn('HasKey', 'Key'),
        ];
    }

    public function deleteMessage()
    {
        return fn(DeployServerModel $item) => "Delete server {$item->Name} ({$item->Host})?";
    }
}
