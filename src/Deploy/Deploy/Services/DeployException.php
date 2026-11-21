<?php

namespace SaasPaws\Deploy\Deploy\Services;

use RuntimeException;

/** A deployment step refused or failed. Carries the stage so callers can record where it stopped. */
class DeployException extends RuntimeException
{
    public function __construct(string $message, public ?string $stage = null)
    {
        parent::__construct($message);
    }
}
