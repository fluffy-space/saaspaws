<?php

namespace SaasPaws\Deploy\Deploy\Controllers;

use SaasPaws\Deploy\Deploy\Data\Entities\DeploySourceEntity;
use SaasPaws\Deploy\Deploy\Data\Entities\DeploySourceEntityMap;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeploySourceRepository;
use SaasPaws\Deploy\DeployCapability;
use SaasPaws\Deploy\Deploy\Components\Models\DeploySourceModel;
use SaasPaws\Deploy\Deploy\Components\Models\DeploySourceValidation;
use Fluffy\Controllers\BaseController;
use Fluffy\Data\Mapper\IMapper;
use Fluffy\Services\Auth\AuthorizationService;
use Throwable;
use Fluffy\Data\Query\Search;

/**
 * CRUD for where deployable code comes from (`/api/admin/deploy-source`).
 *
 * Dev-only routes, `ManageDeployments` on every handler — see DeployServerController. The git
 * access token is write-only for the same reason the SSH keys are: a token that can be read back
 * out of the admin API is a token that leaks with a session.
 */
class DeploySourceController extends BaseController
{
    public function __construct(
        private IMapper $mapper,
        private DeploySourceRepository $sources,
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
                [DeploySourceEntityMap::PROPERTY_Name, 'like', Search::contains($search)],
                [DeploySourceEntityMap::PROPERTY_GitRepository, 'like', Search::contains($search)],
            ];
        }
        $entities = $this->sources->search($where, [DeploySourceEntityMap::PROPERTY_Name => 1], $page, $size);
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
        $entity = $this->sources->getById($id);
        if (!$entity) {
            return $this->NotFound();
        }
        return $this->toModel($entity);
    }

    public function Create(DeploySourceModel $model)
    {
        if (!$this->auth->authorizeAdminCapability(DeployCapability::ManageDeployments)) {
            return $this->Forbidden();
        }
        $errors = $this->validate($model);
        if (count($errors) > 0) {
            return $this->BadRequest($errors);
        }
        if ($this->nameTaken($model->Name, 0)) {
            return $this->BadRequest(["A source named '{$model->Name}' already exists."]);
        }
        /** @var DeploySourceEntity $entity */
        $entity = $this->mapper->map(DeploySourceEntity::class, $model);
        $this->sources->create($entity);
        return $this->toModel($entity);
    }

    public function Update(int $id, DeploySourceModel $model)
    {
        if (!$this->auth->authorizeAdminCapability(DeployCapability::ManageDeployments)) {
            return $this->Forbidden();
        }
        $errors = $this->validate($model);
        if (count($errors) > 0) {
            return $this->BadRequest($errors);
        }
        /** @var ?DeploySourceEntity $entity */
        $entity = $this->sources->getById($id);
        if (!$entity) {
            return $this->NotFound();
        }
        if ($this->nameTaken($model->Name, $entity->Id)) {
            return $this->BadRequest(["A source named '{$model->Name}' already exists."]);
        }
        $keptId = $entity->Id;
        $keptCreatedOn = $entity->CreatedOn;
        $keptToken = $entity->GitAccessToken;

        /** @var DeploySourceEntity $entity */
        $entity = $this->mapper->map(DeploySourceEntity::class, $model, $entity);
        // See DeployServerController::Update — without these the write matches no row.
        $entity->Id = $keptId;
        $entity->CreatedOn = $keptCreatedOn;
        $entity->GitAccessToken = $model->GitAccessToken === null || trim($model->GitAccessToken) === ''
            ? $keptToken
            : $model->GitAccessToken;

        $this->sources->update($entity);
        return $this->toModel($entity);
    }

    public function Delete(int $id)
    {
        if (!$this->auth->authorizeAdminCapability(DeployCapability::ManageDeployments)) {
            return $this->Forbidden();
        }
        $entity = $this->sources->getById($id);
        if (!$entity) {
            return $this->NotFound();
        }
        try {
            // Applications RESTRICT on SourceId: a source still wired to one must not vanish
            // under it.
            return $this->sources->delete($entity);
        } catch (Throwable) {
            return $this->BadRequest([
                'This source is used by an application or its deployment history, and cannot be deleted.',
            ]);
        }
    }

    /**
     * `Name` is uniquely indexed (case-insensitively), so a duplicate would otherwise surface as
     * a raw 500 from the database instead of something the form can show.
     */
    private function nameTaken(string $name, int $exceptId): bool
    {
        /** @var ?DeploySourceEntity $existing */
        $existing = $this->sources->find(DeploySourceEntityMap::PROPERTY_Name, trim($name));
        return $existing !== null && $existing->Id !== $exceptId;
    }

    private function toModel(DeploySourceEntity $entity): DeploySourceModel
    {
        /** @var DeploySourceModel $model */
        $model = $this->mapper->map(DeploySourceModel::class, $entity);
        $model->HasToken = trim((string) $entity->GitAccessToken) !== '';
        $model->GitAccessToken = null;
        return $model;
    }

    /** @return string[] */
    private function validate(DeploySourceModel $model): array
    {
        $messages = [];
        foreach ((new DeploySourceValidation($model))->getValidationRules() as $property => $rules) {
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
