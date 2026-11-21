<?php

namespace SaasPaws\Deploy\Deploy\Components\Views;

use SaasPaws\Deploy\Deploy\Components\Models\DeployApplicationModel;
use SaasPaws\Deploy\Deploy\Components\Models\DeployApplicationValidation;
use Pupils\Components\Guards\HasCapability;
use Viewi\Components\Attributes\Middleware;
use Viewi\Components\BaseComponent;
use Viewi\Components\DOM\DomEvent;
use Viewi\Components\Environment\ClientTimer;
use Viewi\Components\Http\HttpClient;
use Viewi\Components\Routing\ClientRoute;
use Viewi\UI\Components\Alerts\AlertService;
use Viewi\UI\Components\Buttons\ActionButton;
use Viewi\UI\Components\Forms\ActionForm;
use Viewi\UI\Components\Validation\ValidationMessage;

/**
 * /admin/deploy-application/{id} — everything about one application, in tabs.
 *
 * Standalone rather than an `EditPage` subclass (like `SubscriptionEdit`): it needs its own
 * HttpClient for the source and server pickers, which EditPage's private client cannot serve.
 *
 * The servers, the units and the upstreams are saved together with the application: it is only
 * deployable when all three agree, and separate screens would let it sit half-configured with
 * nothing on the form to say so.
 */
#[Middleware([[HasCapability::class, 'ManageDeployments']])]
class DeployApplicationEdit extends BaseComponent
{
    public ?DeployApplicationModel $item = null;
    public ?DeployApplicationValidation $validation = null;
    public bool $createMode = false;
    public int $state = ActionButton::STATE_PENDING;
    private ?ActionForm $actionForm = null;
    public ?ValidationMessage $generalMessages = null;

    /** Picker options. */
    public array $sources = [];
    public array $servers = [];

    /** Flattened for the header — a template must not index a component property. */
    public string $liveSummary = '';

    public function __construct(
        public int $id,
        private HttpClient $http,
        private AlertService $messages,
        private ClientRoute $route
    ) {}

    public function init()
    {
        $this->http->get('/api/admin/deploy-application/sources')
            ->then(function ($data) {
                $this->sources = $data;
            }, function () {
                // the select stays empty; saving still validates server-side
            });
        $this->http->get('/api/admin/deploy-application/servers')
            ->then(function ($data) {
                $this->servers = $data;
            }, function () {
            });

        if ($this->id > 0) {
            $this->http->get("/api/admin/deploy-application/{$this->id}")
                ->then(function (DeployApplicationModel $item) {
                    $this->apply($item);
                }, function () {
                });
        } else {
            $this->createMode = true;
            $this->apply(new DeployApplicationModel());
        }
    }

    public function apply(DeployApplicationModel $item)
    {
        $this->item = $item;
        $this->validation = new DeployApplicationValidation($item);
        $this->liveSummary = $item->LiveSlot
            ? "Live on '{$item->LiveSlot}'" . ($item->LiveCommitSha ? ' at ' . substr($item->LiveCommitSha, 0, 12) : '')
            : 'Nothing observed live yet';
    }

    /**
     * Checkbox state for one server. Arrays are not reactive, so toggling assigns a NEW array
     * instead of pushing into the existing one — a push would not re-render.
     */
    public function hasServer(int $serverId): bool
    {
        return $this->item !== null && in_array($serverId, $this->item->ServerIds);
    }

    public function toggleServer(int $serverId)
    {
        if ($this->item === null) {
            return;
        }
        $next = [];
        $removed = false;
        foreach ($this->item->ServerIds as $id) {
            if ((int) $id === $serverId) {
                $removed = true;
                continue;
            }
            $next[] = (int) $id;
        }
        if (!$removed) {
            $next[] = $serverId;
        }
        $this->item->ServerIds = $next;
    }

    public function onSave(DomEvent $event)
    {
        $event->preventDefault();
        if (!$this->actionForm->validate()) {
            return;
        }
        $this->state = ActionButton::STATE_PROCESSING;
        $this->http->request(
            $this->createMode ? 'post' : 'put',
            $this->createMode ? '/api/admin/deploy-application' : "/api/admin/deploy-application/{$this->id}",
            $this->item
        )->then(function ($saved) {
            $this->stopLoading(ActionButton::STATE_SUCCESS);
            if ($saved !== null) {
                $savedText = $this->createMode ? 'created' : 'saved';
                $this->messages->success("Application was successfully $savedText.", 5000);
                if ($this->createMode) {
                    $this->route->navigate("/admin/deploy-application/{$saved->Id}");
                } else {
                    // Re-apply what came back: the ids of new service and upstream rows are on it,
                    // and saving again with the old (zero) ids would create duplicates.
                    $this->apply($saved);
                }
            }
        }, function ($response) {
            $this->stopLoading(ActionButton::STATE_ERROR);
            $this->handleErrors($response->body);
        });
    }

    public function handleErrors($body)
    {
        if ($body && $this->generalMessages !== null) {
            $messages = $body;
            if (isset($body['errors'])) {
                $messages = $body['errors'];
            }
            $this->generalMessages->messages = is_array($messages) ? $messages : [$messages];
            $this->generalMessages->show = true;
        }
    }

    public function stopLoading(int $newState)
    {
        ClientTimer::setTimeoutStatic(fn() => ($this->state = $newState), 500);
        ClientTimer::setTimeoutStatic(fn() => ($this->state = ActionButton::STATE_PENDING), 2500);
    }
}
