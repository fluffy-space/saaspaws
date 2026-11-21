<?php

namespace SaasPaws\Deploy\Deploy\Components\Views;

use SaasPaws\Deploy\Deploy\Components\Models\DeployApplicationModel;
use Pupils\Components\Guards\HasCapability;
use Viewi\Components\Attributes\Middleware;
use Viewi\Components\BaseComponent;
use Viewi\UI\Components\Tables\TableColumn;

/** /admin/deploy-application — what can be deployed, and what is live where. */
#[Middleware([[HasCapability::class, 'ManageDeployments']])]
class DeployApplicationList extends BaseComponent
{
    public array $columns = [];

    public function init()
    {
        $this->columns = [
            new TableColumn('Id'),
            new TableColumn('Name'),
            new TableColumn('Domain'),
            new TableColumn('Strategy'),
            new TableColumn('LiveSlot', 'Live'),
            new TableColumn('LastDeployedOn', 'Last deploy'),
        ];
    }

    /** Spelled-out class names — a concatenated one is invisible to the CSS tree-shaker. */
    public function slotClass(?string $slot): string
    {
        if ($slot === 'blue') {
            return 'badge text-bg-primary';
        }
        if ($slot === 'green') {
            return 'badge text-bg-success';
        }
        return 'badge text-bg-light text-muted';
    }

    public function formatTime(?int $microseconds): string
    {
        if (!$microseconds) {
            return '—';
        }
        return gmdate('Y-m-d H:i', (int) ($microseconds / 1000000)) . ' UTC';
    }

    public function deleteMessage()
    {
        return fn(DeployApplicationModel $item) => "Delete application {$item->Name}?";
    }
}
