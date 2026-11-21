<?php

namespace SaasPaws\Deploy\Deploy\Components\Models;

use SharedPaws\Validation\IValidationRules;
use SharedPaws\Validation\ValidationRules;

class DeployServerValidation implements IValidationRules
{
    public function __construct(private DeployServerModel $model) {}

    public function getValidationRules(): array
    {
        return ValidationRules::rules($this->model)
            ->required('Name')
            ->required('Host')
            ->required('UserName')
            ->toList();
    }
}
