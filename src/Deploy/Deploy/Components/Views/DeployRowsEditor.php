<?php

namespace SaasPaws\Deploy\Deploy\Components\Views;

use SaasPaws\Deploy\Deploy\Components\Models\DeployServiceModel;
use SaasPaws\Deploy\Deploy\Components\Models\DeployUpstreamModel;
use Viewi\Components\BaseComponent;
use Viewi\Components\Http\HttpClient;

/**
 * The Services and Upstreams tables of the application edit page.
 *
 * Editable ROWS rather than a textarea or a JSON blob, deliberately: free text would put a parser
 * between the operator and the deploy, and a typo'd port or slot would only surface mid-rotation,
 * after the build, with traffic about to move. A select for the slot, a number for the port and a
 * dropdown of the application's own upstreams means most invalid states cannot be typed at all.
 *
 * Operates on the shared `item` (DeployApplicationModel) in place — the parent saves it. Arrays
 * are NOT reactive here, so every add/remove assigns a NEW array; pushing into the existing one
 * would change the data and render nothing.
 */
class DeployRowsEditor extends BaseComponent
{
    /** The shared DeployApplicationModel (mutated in place). */
    public $item = null;

    public bool $generating = false;
    public string $error = '';

    /** A property, not a method: a foreach over a method call never re-renders. */
    public array $slots = ['', 'blue', 'green'];

    public function __construct(private HttpClient $http) {}

    // ---------------------------------------------------------------- upstreams

    public function addUpstream()
    {
        if ($this->item === null) {
            return;
        }
        $row = new DeployUpstreamModel();
        $row->Location = '/';
        $next = [];
        foreach ($this->item->Upstreams as $upstream) {
            $next[] = $upstream;
        }
        $next[] = $row;
        $this->item->Upstreams = $next;
    }

    public function removeUpstream(int $index)
    {
        if ($this->item === null) {
            return;
        }
        $next = [];
        foreach ($this->item->Upstreams as $i => $upstream) {
            if ($i !== $index) {
                $next[] = $upstream;
            }
        }
        $this->item->Upstreams = $next;
    }

    // ---------------------------------------------------------------- services

    public function addService()
    {
        if ($this->item === null) {
            return;
        }
        $next = [];
        foreach ($this->item->Services as $service) {
            $next[] = $service;
        }
        $next[] = new DeployServiceModel();
        $this->item->Services = $next;
    }

    public function removeService(int $index)
    {
        if ($this->item === null) {
            return;
        }
        $next = [];
        foreach ($this->item->Services as $i => $service) {
            if ($i !== $index) {
                $next[] = $service;
            }
        }
        $this->item->Services = $next;
    }

    // ---------------------------------------------------------------- generation

    /**
     * Offered only while both tables are empty, so it can never clobber a hand-edited setup.
     * What comes back is filled into the form and stays editable — nothing is written until the
     * page is saved.
     */
    public function canGenerate(): bool
    {
        return $this->item !== null
            && count($this->item->Services) === 0
            && count($this->item->Upstreams) === 0;
    }

    public function generate()
    {
        if ($this->item === null) {
            return;
        }
        $this->error = '';
        $this->generating = true;
        $this->http->post('/api/admin/deploy-application/generate', [
            'ServicePrefix' => $this->item->ServicePrefix,
            'BasePort' => $this->item->BasePort,
        ])->then(function ($data) {
            $this->generating = false;
            $this->item->Upstreams = $data['Upstreams'];
            $this->item->Services = $data['Services'];
        }, function ($response) {
            $this->generating = false;
            $this->error = 'Set a service prefix and a base port first (e.g. myapp + 8221).';
        });
    }
}
