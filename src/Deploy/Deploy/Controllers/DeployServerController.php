<?php

namespace SaasPaws\Deploy\Deploy\Controllers;

use SaasPaws\Deploy\Deploy\Data\Entities\DeployServerEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployServerEntityMap;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeployServerRepository;
use SaasPaws\Deploy\DeployCapability;
use SaasPaws\Deploy\Deploy\Components\Models\DeployServerModel;
use SaasPaws\Deploy\Deploy\Components\Models\DeployServerValidation;
use Fluffy\Controllers\BaseController;
use Fluffy\Data\Mapper\IMapper;
use Fluffy\Services\Auth\AuthorizationService;
use Throwable;
use Fluffy\Data\Query\Search;

/**
 * CRUD for the boxes we deploy to (`/api/admin/deploy-server`).
 *
 * The routes exist in devMode only, and every handler still checks `ManageDeployments`
 * (SuperAdmin-only) because controller discovery is folder-wide — the class is present on every
 * box whether its routes are or not.
 *
 * **Key material is write-only.** Reads blank the private key, the passphrase and the sudo
 * password and report `HasKey` / `HasPassword` instead, so a key can be set but never read back —
 * not by a browser, not by a stolen session. A blank value on save therefore means "keep what is
 * stored": the alternative is that opening a server and pressing Save silently wipes its key.
 */
class DeployServerController extends BaseController
{
    public function __construct(
        private IMapper $mapper,
        private DeployServerRepository $servers,
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
                [DeployServerEntityMap::PROPERTY_Name, 'like', Search::contains($search)],
                [DeployServerEntityMap::PROPERTY_Host, 'like', Search::contains($search)],
            ];
        }
        $entities = $this->servers->search($where, [DeployServerEntityMap::PROPERTY_Name => 1], $page, $size);
        return [
            'list' => array_map(fn($entity) => $this->toModel($entity), $entities['list']),
            'total' => $entities['total'],
        ];
    }

    public function Get(int $id)
    {
        if (!$this->auth->authorizeAdminCapability(DeployCapability::ManageDeployments)) {
            return $this->Forbidden();
        }
        $entity = $this->servers->getById($id);
        if (!$entity) {
            return $this->NotFound();
        }
        return $this->toModel($entity);
    }

    public function Create(DeployServerModel $model)
    {
        if (!$this->auth->authorizeAdminCapability(DeployCapability::ManageDeployments)) {
            return $this->Forbidden();
        }
        $errors = $this->validate($model);
        if (count($errors) > 0) {
            return $this->BadRequest($errors);
        }
        if ($this->nameTaken($model->Name, 0)) {
            return $this->BadRequest(["A server named '{$model->Name}' already exists."]);
        }
        /** @var DeployServerEntity $entity */
        $entity = $this->mapper->map(DeployServerEntity::class, $model);
        $this->servers->create($entity);
        return $this->toModel($entity);
    }

    public function Update(int $id, DeployServerModel $model)
    {
        if (!$this->auth->authorizeAdminCapability(DeployCapability::ManageDeployments)) {
            return $this->Forbidden();
        }
        $errors = $this->validate($model);
        if (count($errors) > 0) {
            return $this->BadRequest($errors);
        }
        /** @var ?DeployServerEntity $entity */
        $entity = $this->servers->getById($id);
        if (!$entity) {
            return $this->NotFound();
        }
        if ($this->nameTaken($model->Name, $entity->Id)) {
            return $this->BadRequest(["A server named '{$model->Name}' already exists."]);
        }
        $kept = [
            'Id' => $entity->Id,
            'CreatedOn' => $entity->CreatedOn,
            'PrivateFileKey' => $entity->PrivateFileKey,
            'PublicFileKey' => $entity->PublicFileKey,
            'FileKeyPassphrase' => $entity->FileKeyPassphrase,
            'Password' => $entity->Password,
        ];

        /** @var DeployServerEntity $entity */
        $entity = $this->mapper->map(DeployServerEntity::class, $model, $entity);
        // The model carries BaseModel's Id/CreatedOn defaults of 0 — mapping them over the entity
        // would leave the UPDATE matching no row at all, silently.
        $entity->Id = $kept['Id'];
        $entity->CreatedOn = $kept['CreatedOn'];
        // Blank means "keep", never "clear" — see the class docblock.
        $entity->PrivateFileKey = $this->keepIfBlank($model->PrivateFileKey, $kept['PrivateFileKey']);
        $entity->PublicFileKey = $this->keepIfBlank($model->PublicFileKey, $kept['PublicFileKey']);
        $entity->FileKeyPassphrase = $this->keepIfBlank($model->FileKeyPassphrase, $kept['FileKeyPassphrase']);
        $entity->Password = $this->keepIfBlank($model->Password, $kept['Password']);

        $this->servers->update($entity);
        return $this->toModel($entity);
    }

    public function Delete(int $id)
    {
        if (!$this->auth->authorizeAdminCapability(DeployCapability::ManageDeployments)) {
            return $this->Forbidden();
        }
        $entity = $this->servers->getById($id);
        if (!$entity) {
            return $this->NotFound();
        }
        try {
            // Application links CASCADE; deployment history RESTRICTs, which is the point — the
            // audit trail outlives the convenience of deleting a server.
            return $this->servers->delete($entity);
        } catch (Throwable) {
            return $this->BadRequest(['This server has deployment history and cannot be deleted.']);
        }
    }

    /**
     * `Name` is uniquely indexed (case-insensitively), so a duplicate would otherwise surface as
     * a raw 500 from the database instead of something the form can show.
     */
    private function nameTaken(string $name, int $exceptId): bool
    {
        /** @var ?DeployServerEntity $existing */
        $existing = $this->servers->find(DeployServerEntityMap::PROPERTY_Name, trim($name));
        return $existing !== null && $existing->Id !== $exceptId;
    }

    private function keepIfBlank(?string $submitted, ?string $stored): ?string
    {
        return $submitted === null || trim($submitted) === '' ? $stored : $submitted;
    }

    private function toModel(DeployServerEntity $entity): DeployServerModel
    {
        /** @var DeployServerModel $model */
        $model = $this->mapper->map(DeployServerModel::class, $entity);
        $model->HasKey = trim((string) $entity->PrivateFileKey) !== '';
        $model->HasPassword = trim((string) $entity->Password) !== '';
        $model->PrivateFileKey = null;
        $model->PublicFileKey = null;
        $model->FileKeyPassphrase = null;
        $model->Password = null;
        return $model;
    }

    /** @return string[] */
    private function validate(DeployServerModel $model): array
    {
        $messages = [];
        foreach ((new DeployServerValidation($model))->getValidationRules() as $property => $rules) {
            foreach ($rules as $rule) {
                $result = $rule();
                if ($result !== true) {
                    $messages[] = $result === false ? "Validation has failed for $property." : $result;
                }
            }
        }
        return $messages;
    }
}
