<?php

namespace SaasPaws\Deploy\Deploy\Components\Models;

use SharedPaws\Models\BaseModel;

/**
 * A deployable application, with everything the tabbed edit page writes in one save.
 *
 * The servers, the services and the upstreams are edited here rather than on screens of their
 * own: an application is only deployable when all three agree, and separate screens would let it
 * sit half-configured with nothing on the form to say so.
 *
 * `KeyVault` — the production secrets file pushed to the servers — is READ-WRITE here, unlike the
 * server keys and the git token: it is a JSON document that gets edited key by key, and editing what
 * you cannot read is how it ended up empty. Safe because the whole deploy surface is dev-only and
 * SuperAdmin-only (see DeployApplicationController::toModel). `HasKeyVault` says whether one is
 * stored, and blank on save still means "keep the stored one".
 */
class DeployApplicationModel extends BaseModel
{
    public string $Name = '';
    public ?string $Description = null;
    /** Holds the release; under `rotate` the slots are its child directories. */
    public string $RootFolder = '';
    public ?string $Subfolder = null;
    public ?string $Domain = null;
    public string $Environment = 'prod';
    /** Build key passed to `php fluffy build`. */
    public ?string $BuildParams = 'prod';
    // Loose on purpose — see the note on the numbers below: the "— none —" option carries value="".
    public mixed $SourceId = null;

    public ?string $KeyVault = null;
    public bool $HasKeyVault = false;
    /** Vault file name; blank derives values.<Environment>.json. */
    public ?string $KeyVaultFile = null;

    /** rotate (blue/green) | restart (replace in place, with the downtime that implies). */
    public string $Strategy = 'rotate';
    /** Unit-name prefix used to GENERATE the blue/green rows. */
    public ?string $ServicePrefix = null;
    // Loose types, not sloppy ones: the request binder assigns the raw JSON value onto these with
    // `StdMapper`, and a `model=`-bound number input sends the STRING '' as soon as it is cleared.
    // Against `int` that is a TypeError thrown before `Create`/`Update` is entered, so the form
    // would get a 500 where it should get a validation message. `normalize()` casts all three.
    /** First port of the generated pair; the second slot takes the next one. */
    public mixed $BasePort = 0;
    public mixed $DrainSeconds = 30;
    public string $HealthPath = '/health';
    /** Run the verification suite against the new slot before traffic moves; a failure aborts. */
    public bool $VerifyBeforeRotate = false;

    // Last-observed state; read-only on the form — a stale page must not be able to rewrite
    // which slot is serving.
    public ?string $LiveSlot = null;
    public ?string $LiveCommitSha = null;
    public ?int $LastDeployedOn = null;

    /** @var int[] Servers this application deploys to. */
    public array $ServerIds = [];
    /** @var DeployServiceModel[] */
    public array $Services = [];
    /** @var DeployUpstreamModel[] */
    public array $Upstreams = [];
}
