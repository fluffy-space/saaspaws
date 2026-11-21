<?php

namespace SaasPaws\Deploy\Deploy\Components\Models;

use SharedPaws\Validation\IValidationRules;
use SharedPaws\Validation\ValidationRules;

class DeployApplicationValidation implements IValidationRules
{
    public function __construct(private DeployApplicationModel $model) {}

    public function getValidationRules(): array
    {
        return ValidationRules::rules($this->model)
            ->required('Name')
            ->required('RootFolder')
            ->required('Environment')
            ->toList();
    }
}
