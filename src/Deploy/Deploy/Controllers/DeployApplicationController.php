<?php

namespace SaasPaws\Deploy\Deploy\Controllers;

use SaasPaws\Deploy\Deploy\Data\Entities\DeployApplicationEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployApplicationEntityMap;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployApplicationServerEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployApplicationServerEntityMap;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployServiceEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployServiceEntityMap;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployUpstreamEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployUpstreamEntityMap;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeployApplicationRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeployApplicationServerRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeployServerRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeployServiceRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeploySourceRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeployUpstreamRepository;
use SaasPaws\Deploy\DeployCapability;
use SaasPaws\Deploy\Deploy\Components\Models\DeployApplicationModel;
use SaasPaws\Deploy\Deploy\Components\Models\DeployApplicationValidation;
use SaasPaws\Deploy\Deploy\Components\Models\DeployGenerateModel;
use SaasPaws\Deploy\Deploy\Components\Models\DeployServiceModel;
use SaasPaws\Deploy\Deploy\Components\Models\DeployUpstreamModel;
use Fluffy\Controllers\BaseController;
use Fluffy\Data\Mapper\IMapper;
use Fluffy\Services\Auth\AuthorizationService;
use Throwable;
use Fluffy\Data\Query\Search;

/**
 * CRUD for deployable applications (`/api/admin/deploy-application`), including the servers they
 * deploy to, their systemd units and their nginx upstreams.
 *
 * All three are written in ONE save rather than on screens of their own. An application is only
 * deployable when the units, the upstreams and the servers agree with each other, and separate
 * screens would let it sit half-configured with nothing on the form to say so.
 *
 * Services carry their upstream by NAME (`DeployServiceModel::$UpstreamName`): the rows are
 * edited — and generated — before an unsaved upstream has an id, so a name is the only handle
 * that exists at that point. Upstreams are therefore written first, and the names resolved
 * against what came back.
 */
class DeployApplicationController extends BaseController
{
    public function __construct(
        private IMapper $mapper,
        private DeployApplicationRepository $applications,
        private DeployApplicationServerRepository $links,
        private DeployServerRepository $servers,
        private DeploySourceRepository $sources,
        private DeployServiceRepository $services,
        private DeployUpstreamRepository $upstreams,
        private AuthorizationService $auth,
    ) {}

    public function List(int $page = 1, int $size = 10, ?string $search = null)
    {
        if (!$this->auth->authorizeAdminCapability(DeployCapability::ManageDeployments)) {
            return $this->Forbidden();
        }
        $where = [];
        $search = trim($search ?? '');
        if ($search !== '') {
            $where[] = [
                [DeployApplicationEntityMap::PROPERTY_Name, 'like', Search::contains($search)],
                [DeployApplicationEntityMap::PROPERTY_Domain, 'like', Search::contains($search)],
            ];
        }
        $entities = $this->applications->search($where, [DeployApplicationEntityMap::PROPERTY_Name => 1], $page, $size);
        // The list does not resolve the child rows: it shows one line per application, and three
        // extra queries per row for data nothing displays is a page that gets slower with use.
        return [
            'list' => array_map(fn($entity) => $this->mapper->map(DeployApplicationModel::class, $entity), $entities['list']),
            'total' => $entities['total'],
        ];
    }

    public function Get(int $id)
    {
        if (!$this->auth->authorizeAdminCapability(DeployCapability::ManageDeployments)) {
            return $this->Forbidden();
        }
        /** @var ?DeployApplicationEntity $entity */
        $entity = $this->applications->getById($id);
        if (!$entity) {
            return $this->NotFound();
        }
        return $this->toModel($entity);
    }

    public function Create(DeployApplicationModel $model)
    {
        if (!$this->auth->authorizeAdminCapability(DeployCapability::ManageDeployments)) {
            return $this->Forbidden();
        }
        $this->normalize($model);
        $errors = $this->validate($model);
        if (count($errors) > 0) {
            return $this->BadRequest($errors);
        }
        if ($this->nameTaken($model->Name, 0)) {
            return $this->BadRequest(["An application named '{$model->Name}' already exists."]);
        }
        /** @var DeployApplicationEntity $entity */
        $entity = $this->mapper->map(DeployApplicationEntity::class, $model);
        // Live state is the pipeline's to write, never the form's.
        $entity->LiveSlot = null;
        $entity->LiveCommitSha = null;
        $entity->LastDeployedOn = null;
        $this->applications->create($entity);

        $this->syncServers($entity->Id, $model->ServerIds);
        $names = $this->syncUpstreams($entity->Id, $model->Upstreams);
        $this->syncServices($entity->Id, $model->Services, $names);
        return $this->toModel($entity);
    }

    public function Update(int $id, DeployApplicationModel $model)
    {
        if (!$this->auth->authorizeAdminCapability(DeployCapability::ManageDeployments)) {
            return $this->Forbidden();
        }
        $this->normalize($model);
        $errors = $this->validate($model);
        if (count($errors) > 0) {
            return $this->BadRequest($errors);
        }
        /** @var ?DeployApplicationEntity $entity */
        $entity = $this->applications->getById($id);
        if (!$entity) {
            return $this->NotFound();
        }
        if ($this->nameTaken($model->Name, $entity->Id)) {
            return $this->BadRequest(["An application named '{$model->Name}' already exists."]);
        }
        $kept = [
            'Id' => $entity->Id,
            'CreatedOn' => $entity->CreatedOn,
            'KeyVault' => $entity->KeyVault,
            'LiveSlot' => $entity->LiveSlot,
            'LiveCommitSha' => $entity->LiveCommitSha,
            'LastDeployedOn' => $entity->LastDeployedOn,
        ];

        /** @var DeployApplicationEntity $entity */
        $entity = $this->mapper->map(DeployApplicationEntity::class, $model, $entity);
        // See DeployServerController::Update — without these the write matches no row.
        $entity->Id = $kept['Id'];
        $entity->CreatedOn = $kept['CreatedOn'];
        // Blank means "keep". The form is prefilled with the stored vault now (see toModel), so this
        // is no longer about a browser that never received it — it is about the ways a form arrives
        // empty without anyone meaning to clear the field: a save from a tab that failed to load,
        // an older client, a scripted PUT that omits the property. None of those should be able to
        // erase production secrets. Clearing the vault on purpose is not a thing the form does; the
        // vault is replaced by pasting a new one.
        $entity->KeyVault = $model->KeyVault === null || trim($model->KeyVault) === ''
            ? $kept['KeyVault']
            : $model->KeyVault;
        // A stale page must not be able to rewrite which slot is serving.
        $entity->LiveSlot = $kept['LiveSlot'];
        $entity->LiveCommitSha = $kept['LiveCommitSha'];
        $entity->LastDeployedOn = $kept['LastDeployedOn'];

        $this->applications->update($entity);
        $this->syncServers($entity->Id, $model->ServerIds);
        $names = $this->syncUpstreams($entity->Id, $model->Upstreams);
        $this->syncServices($entity->Id, $model->Services, $names);
        return $this->toModel($entity);
    }

    public function Delete(int $id)
    {
        if (!$this->auth->authorizeAdminCapability(DeployCapability::ManageDeployments)) {
            return $this->Forbidden();
        }
        $entity = $this->applications->getById($id);
        if (!$entity) {
            return $this->NotFound();
        }
        try {
            // Servers, services and upstreams CASCADE; deployment history RESTRICTs.
            return $this->applications->delete($entity);
        } catch (Throwable) {
            return $this->BadRequest(['This application has deployment history and cannot be deleted.']);
        }
    }

    /** GET /api/admin/deploy-application/servers — the server picker's options. */
    public function Servers()
    {
        if (!$this->auth->authorizeAdminCapability(DeployCapability::ManageDeployments)) {
            return $this->Forbidden();
        }
        $entities = $this->servers->search([], ['Name' => 1], 1, 200, false)['list'];
        return array_map(fn($server) => [
            'Id' => $server->Id,
            'Name' => $server->Name,
            'Host' => $server->Host,
        ], $entities);
    }

    /** GET /api/admin/deploy-application/sources — the source picker's options. */
    public function Sources()
    {
        if (!$this->auth->authorizeAdminCapability(DeployCapability::ManageDeployments)) {
            return $this->Forbidden();
        }
        $entities = $this->sources->search([], ['Name' => 1], 1, 200, false)['list'];
        return array_map(fn($source) => [
            'Id' => $source->Id,
            'Name' => $source->Name,
            'GitRepository' => $source->GitRepository,
            'GitBranch' => $source->GitBranch,
        ], $entities);
    }

    /**
     * POST /api/admin/deploy-application/generate — the blue/green rows for the common case.
     *
     * Returns them to the form WITHOUT writing anything: they land in the same editable tables as
     * hand-typed rows, so an application that needs a second upstream or a worker adds those and
     * saves once. Generating over an application that already has rows is the caller's decision
     * not to make — the button is only offered when the tables are empty.
     */
    public function Generate(DeployGenerateModel $model)
    {
        if (!$this->auth->authorizeAdminCapability(DeployCapability::ManageDeployments)) {
            return $this->Forbidden();
        }
        $prefix = trim((string) ($model->ServicePrefix ?? ''));
        if ($prefix === '') {
            return $this->BadRequest(['A service prefix is required to generate the rows.']);
        }
        $basePort = (int) $model->BasePort;
        if ($basePort < 1 || $basePort > 65534) {
            return $this->BadRequest(['The base port must be between 1 and 65534 (the second slot takes the next one).']);
        }

        $upstream = new DeployUpstreamModel();
        $upstream->Name = $prefix . '_app';
        $upstream->Location = '/';
        $upstream->ConfPathResolved = "/etc/nginx/conf.d/10-upstream-{$upstream->Name}.conf";
        $upstream->DisplayOrder = 0;

        $services = [];
        foreach (['blue', 'green'] as $index => $slot) {
            $service = new DeployServiceModel();
            $service->Name = "$prefix-$slot";
            $service->Slot = $slot;
            $service->Port = $basePort + $index;
            $service->UpstreamName = $upstream->Name;
            $service->DisplayOrder = $index;
            $services[] = $service;
        }
        return ['Upstreams' => [$upstream], 'Services' => $services];
    }

    // ------------------------------------------------------------------ child rows

    /**
     * Turn the submitted child rows into typed models.
     *
     * The request binder maps a JSON body with `StdMapper`, which copies array values through
     * VERBATIM — a `@var DeployServiceModel[]` docblock buys nothing there, so `$model->Services`
     * arrives as an array of plain `stdClass`. Reading those directly appears to work and then
     * warns (or silently reads null) the moment a form omits a key, and none of the model's
     * defaults apply. So they are converted once, here, and everything downstream is typed.
     */
    private function normalize(DeployApplicationModel $model): void
    {
        // The scalars the form can submit as '' — the two number inputs once cleared, and the
        // source select's "— none —" option. They are typed loosely on the model so the binder
        // cannot TypeError before this method is reached, which makes casting them here the step
        // that keeps the entity mapping (and the columns behind it) strictly typed.
        $model->BasePort = (int) $model->BasePort;
        $model->DrainSeconds = (int) $model->DrainSeconds;
        $sourceId = (int) $model->SourceId;
        $model->SourceId = $sourceId > 0 ? $sourceId : null;

        $model->ServerIds = array_values(array_filter(array_map(
            fn($id) => (int) $id,
            is_array($model->ServerIds) ? $model->ServerIds : []
        ), fn(int $id) => $id > 0));

        $upstreams = [];
        foreach (is_array($model->Upstreams) ? $model->Upstreams : [] as $row) {
            $upstream = new DeployUpstreamModel();
            $upstream->Id = (int) $this->field($row, 'Id', 0);
            $upstream->Name = trim((string) $this->field($row, 'Name', ''));
            $location = trim((string) $this->field($row, 'Location', '/'));
            $upstream->Location = $location !== '' ? $location : '/';
            $confPath = trim((string) $this->field($row, 'ConfPath', ''));
            $upstream->ConfPath = $confPath !== '' ? $confPath : null;
            $upstreams[] = $upstream;
        }
        $model->Upstreams = $upstreams;

        $services = [];
        foreach (is_array($model->Services) ? $model->Services : [] as $row) {
            $service = new DeployServiceModel();
            $service->Id = (int) $this->field($row, 'Id', 0);
            $service->Name = trim((string) $this->field($row, 'Name', ''));
            $slot = trim((string) $this->field($row, 'Slot', ''));
            $service->Slot = $slot !== '' ? $slot : null;
            $service->Port = (int) $this->field($row, 'Port', 0);
            $upstreamName = trim((string) $this->field($row, 'UpstreamName', ''));
            $service->UpstreamName = $upstreamName !== '' ? $upstreamName : null;
            $services[] = $service;
        }
        $model->Services = $services;
    }

    /**
     * `Name` is uniquely indexed (case-insensitively), so a duplicate would otherwise surface as
     * a raw 500 from the database instead of something the form can show.
     */
    private function nameTaken(string $name, int $exceptId): bool
    {
        /** @var ?DeployApplicationEntity $existing */
        $existing = $this->applications->find(DeployApplicationEntityMap::PROPERTY_Name, trim($name));
        return $existing !== null && $existing->Id !== $exceptId;
    }

    /** One field of a submitted row, whatever shape the binder left it in. */
    private function field(mixed $row, string $name, mixed $default): mixed
    {
        if (is_array($row)) {
            return $row[$name] ?? $default;
        }
        if (is_object($row)) {
            return $row->$name ?? $default;
        }
        return $default;
    }

    /** Make the server join match the submitted list exactly: add what is new, drop what is gone. */
    private function syncServers(int $applicationId, array $serverIds): void
    {
        $existing = $this->links->search(
            [[DeployApplicationServerEntityMap::PROPERTY_ApplicationId, $applicationId]],
            [DeployApplicationServerEntityMap::PROPERTY_Id => 1],
            1,
            null,
            false
        )['list'];

        $have = [];
        foreach ($existing as $link) {
            /** @var DeployApplicationServerEntity $link */
            $have[$link->ServerId] = $link;
        }
        foreach ($serverIds as $serverId) {
            $serverId = (int) $serverId;
            if ($serverId <= 0) {
                continue;
            }
            if (isset($have[$serverId])) {
                unset($have[$serverId]); // still wanted — keep it
                continue;
            }
            $link = new DeployApplicationServerEntity();
            $link->ApplicationId = $applicationId;
            $link->ServerId = $serverId;
            $this->links->create($link);
        }
        foreach ($have as $link) {
            $this->links->delete($link);
        }
    }

    /**
     * Write the upstream rows and return `name => id` for the services to resolve against.
     *
     * @param DeployUpstreamModel[] $models
     * @return array<string, int>
     */
    private function syncUpstreams(int $applicationId, array $models): array
    {
        $existing = $this->upstreamRows($applicationId);
        $byId = [];
        foreach ($existing as $row) {
            $byId[$row->Id] = $row;
        }

        $names = [];
        $order = 0;
        foreach ($models as $model) {
            $name = trim($model->Name);
            if ($name === '') {
                continue;
            }
            // A fresh entity's Id is uninitialised (BaseEntity declares it untyped-default), so
            // what came back from the database is the only thing that may be asked for one.
            $stored = $model->Id > 0 ? ($byId[$model->Id] ?? null) : null;
            $row = $stored ?? new DeployUpstreamEntity();
            unset($byId[$model->Id]);

            $row->ApplicationId = $applicationId;
            $row->Name = $name;
            $row->Location = trim($model->Location) !== '' ? trim($model->Location) : '/';
            $row->ConfPath = trim((string) $model->ConfPath) !== '' ? trim((string) $model->ConfPath) : null;
            $row->DisplayOrder = $order++;
            $stored !== null ? $this->upstreams->update($row) : $this->upstreams->create($row);
            $names[$name] = $row->Id;
        }
        // Whatever is left was removed on the form. A service still pointing at it is set to NULL
        // by the FK, which is the honest outcome: the unit still exists, it just answers nothing.
        foreach ($byId as $row) {
            $this->upstreams->delete($row);
        }
        return $names;
    }

    /**
     * @param DeployServiceModel[] $models
     * @param array<string, int> $upstreamIds
     */
    private function syncServices(int $applicationId, array $models, array $upstreamIds): void
    {
        $existing = $this->serviceRows($applicationId);
        $byId = [];
        foreach ($existing as $row) {
            $byId[$row->Id] = $row;
        }

        $order = 0;
        foreach ($models as $model) {
            $name = trim($model->Name);
            if ($name === '') {
                continue;
            }
            $stored = $model->Id > 0 ? ($byId[$model->Id] ?? null) : null;
            $row = $stored ?? new DeployServiceEntity();
            unset($byId[$model->Id]);

            $upstreamName = trim((string) $model->UpstreamName);
            $row->ApplicationId = $applicationId;
            $row->Name = $name;
            $row->Slot = trim((string) $model->Slot) !== '' ? trim((string) $model->Slot) : null;
            $row->Port = $model->Port;
            $row->UpstreamId = $upstreamName !== '' ? ($upstreamIds[$upstreamName] ?? null) : null;
            $row->DisplayOrder = $order++;
            $stored !== null ? $this->services->update($row) : $this->services->create($row);
        }
        foreach ($byId as $row) {
            $this->services->delete($row);
        }
    }

    /** @return DeployUpstreamEntity[] */
    private function upstreamRows(int $applicationId): array
    {
        return $this->upstreams->search(
            [[DeployUpstreamEntityMap::PROPERTY_ApplicationId, $applicationId]],
            [DeployUpstreamEntityMap::PROPERTY_DisplayOrder => 1, DeployUpstreamEntityMap::PROPERTY_Id => 1],
            1,
            null,
            false
        )['list'];
    }

    /** @return DeployServiceEntity[] */
    private function serviceRows(int $applicationId): array
    {
        return $this->services->search(
            [[DeployServiceEntityMap::PROPERTY_ApplicationId, $applicationId]],
            [DeployServiceEntityMap::PROPERTY_DisplayOrder => 1, DeployServiceEntityMap::PROPERTY_Id => 1],
            1,
            null,
            false
        )['list'];
    }

    private function toModel(DeployApplicationEntity $entity): DeployApplicationModel
    {
        /** @var DeployApplicationModel $model */
        $model = $this->mapper->map(DeployApplicationModel::class, $entity);
        $model->HasKeyVault = trim((string) $entity->KeyVault) !== '';
        // The vault IS returned to the form, deliberately — it used to be blanked here.
        //
        // Write-only made it unmaintainable in the one way that matters: the vault is a JSON
        // document with a dozen keys, and "add PADDLE_API_KEY" or "fix a typo in email_host" meant
        // reconstructing the whole file from memory and pasting it back, with no way to check what
        // was actually stored. Every empty-vault incident so far came out of that. Being able to
        // read it back is also how "is the stored vault intact?" gets answered without SSH.
        //
        // What makes it acceptable: the entire deploy surface is DEV-ONLY. Outside devMode these
        // routes are never registered (SaasPaws/Deploy/routes.php, DeployStartUp::isDeployEnabled) — so this response
        // exists only on a local control plane, never on a server. On top of that every handler
        // requires ManageDeployments (SuperAdmin). The server keys and the git token are still
        // write-only, because nobody needs to read those back to edit them.

        $links = $this->links->search(
            [[DeployApplicationServerEntityMap::PROPERTY_ApplicationId, $entity->Id]],
            [DeployApplicationServerEntityMap::PROPERTY_ServerId => 1],
            1,
            null,
            false
        )['list'];
        $model->ServerIds = array_map(fn($link) => $link->ServerId, $links);

        $upstreams = $this->upstreamRows($entity->Id);
        $namesById = [];
        $model->Upstreams = array_map(function (DeployUpstreamEntity $row) use (&$namesById) {
            $namesById[$row->Id] = $row->Name;
            /** @var DeployUpstreamModel $upstream */
            $upstream = $this->mapper->map(DeployUpstreamModel::class, $row);
            $upstream->ConfPathResolved = $row->confPath();
            return $upstream;
        }, $upstreams);

        $model->Services = array_map(function (DeployServiceEntity $row) use ($namesById) {
            /** @var DeployServiceModel $service */
            $service = $this->mapper->map(DeployServiceModel::class, $row);
            $service->UpstreamName = $row->UpstreamId !== null ? ($namesById[$row->UpstreamId] ?? null) : null;
            return $service;
        }, $this->serviceRows($entity->Id));

        return $model;
    }

    /**
     * Field validation plus what the child tables must agree on.
     *
     * The row-level checks are the reason these are tables and not a textarea: a duplicate unit
     * name or an upstream that no row defines is caught while the operator is looking at the
     * form, not mid-rotation with traffic about to move.
     *
     * @return string[]
     */
    private function validate(DeployApplicationModel $model): array
    {
        $messages = [];
        foreach ((new DeployApplicationValidation($model))->getValidationRules() as $property => $rules) {
            foreach ($rules as $rule) {
                $result = $rule();
                if ($result !== true) {
                    $messages[] = $result === false ? "Validation has failed for $property." : $result;
                }
            }
        }
        if (!in_array($model->Strategy, ['rotate', 'restart'], true)) {
            $messages[] = "Strategy must be 'rotate' or 'restart'.";
        }

        $upstreamNames = [];
        foreach ($model->Upstreams as $upstream) {
            $name = trim($upstream->Name);
            if ($name === '') {
                $messages[] = 'An upstream needs a name.';
                continue;
            }
            if (isset($upstreamNames[$name])) {
                $messages[] = "Upstream '$name' is listed twice.";
            }
            $upstreamNames[$name] = true;
        }

        $serviceNames = [];
        foreach ($model->Services as $service) {
            $name = trim($service->Name);
            if ($name === '') {
                $messages[] = 'A service needs a unit name.';
                continue;
            }
            if (isset($serviceNames[$name])) {
                $messages[] = "Service '$name' is listed twice.";
            }
            $serviceNames[$name] = true;

            $slot = trim((string) $service->Slot);
            if ($slot !== '' && !in_array($slot, ['blue', 'green'], true)) {
                $messages[] = "Service '$name' has slot '$slot' — it must be blue, green, or empty.";
            }
            if ($service->Port < 0 || $service->Port > 65535) {
                $messages[] = "Service '$name' has an impossible port.";
            }
            $upstreamName = trim((string) $service->UpstreamName);
            if ($upstreamName !== '' && !isset($upstreamNames[$upstreamName])) {
                $messages[] = "Service '$name' points at upstream '$upstreamName', which is not in the list.";
            }
        }
        return $messages;
    }
}
