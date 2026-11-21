<?php

namespace SaasPaws\Deploy\Deploy\Data\Entities;

use Fluffy\Data\Entities\BaseEntity;

/**
 * A box we deploy to, over SSH. Same shape as server-admin's ServerEntity.
 *
 * Secrets (private key, passphrase, password) are write-only: the API blanks them on read and
 * reports Has* flags instead, and a blank value on save keeps what is stored.
 */
class DeployServerEntity extends BaseEntity
{
    public string $Name;
    public ?string $Description = null;
    public ?string $Host = null;
    public int $Port = 22;
    public ?string $UserName = null;
    /** The login user's password — what sudo asks for. Fed to `sudo -S` on stdin. */
    public ?string $Password = null;
    public ?string $PrivateFileKey = null;
    public ?string $PublicFileKey = null;
    public ?string $FileKeyPassphrase = null;
}
