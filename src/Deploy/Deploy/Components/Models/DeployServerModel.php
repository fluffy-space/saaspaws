<?php

namespace SaasPaws\Deploy\Deploy\Components\Models;

use SharedPaws\Models\BaseModel;

/**
 * A box we deploy to, as the admin form sees it.
 *
 * Secrets are WRITE-ONLY: the API blanks `PrivateFileKey` / `PublicFileKey` / `FileKeyPassphrase`
 * / `Password` on every read and reports whether one is stored via `HasKey` / `HasPassword`. An
 * empty value on save therefore means "keep what is stored", not "clear it" — otherwise opening a
 * server and pressing Save would silently wipe its key.
 */
class DeployServerModel extends BaseModel
{
    public string $Name = '';
    public ?string $Description = null;
    public string $Host = '';
    public int $Port = 22;
    public string $UserName = 'root';
    public ?string $PrivateFileKey = null;
    public ?string $PublicFileKey = null;
    /** The key's passphrase. These work under the Swoole SSH client — see the wiki. */
    public ?string $FileKeyPassphrase = null;
    /** The login user's password, which is what sudo asks for. Write-only, like the key. */
    public ?string $Password = null;
    /** Read-only: a key pair is stored. The key itself never leaves the server. */
    public bool $HasKey = false;
    /** Read-only: a sudo password is stored. */
    public bool $HasPassword = false;
}
