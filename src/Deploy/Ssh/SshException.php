<?php

namespace SaasPaws\Deploy\Ssh;

use RuntimeException;

/**
 * Any SSH-level failure: connect, auth, timeout, or a command that exited non-zero when the
 * caller asked for it to be enforced.
 */
class SshException extends RuntimeException
{
    public function __construct(string $message, public ?SshResult $result = null)
    {
        parent::__construct($message);
    }
}
