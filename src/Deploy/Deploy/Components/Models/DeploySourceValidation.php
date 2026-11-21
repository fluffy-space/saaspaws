<?php

namespace SaasPaws\Deploy\Deploy\Components\Models;

use SharedPaws\Validation\IValidationRules;
use SharedPaws\Validation\ValidationRules;

class DeploySourceValidation implements IValidationRules
{
    public function __construct(private DeploySourceModel $model) {}

    public function getValidationRules(): array
    {
        return ValidationRules::rules($this->model)
            ->required('Name')
            ->required('GitRepository')
            ->toList();
    }
}
