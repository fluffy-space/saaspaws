<?php

namespace SaasPaws\Deploy\Deploy\Data\Entities;

use Fluffy\Data\Entities\BaseEntity;

/**
 * A deployable application — server-admin's ApplicationEntity, plus what rotation needs.
 *
 * Two things are children rather than columns, which is the whole extension over server-admin:
 * an app has SEVERAL services (`DeployServiceEntity`) and SEVERAL upstream locations
 * (`DeployUpstreamEntity`), where server-admin had one `ServiceName` / `Upstream` /
 * `UpstreamPort` each.
 */
class DeployApplicationEntity extends BaseEntity
{
    public string $Name;
    public ?string $Description = null;
    /** Release root. Under blue/green the slots are child directories of this. */
    public ?string $RootFolder = null;
    /** server-admin's Subfolder — the app lives in `<root>/<subfolder>` when set. */
    public ?string $Subfolder = null;
    public ?string $Domain = null;
    /** Build key passed to the build command (server-admin's BuildParams). */
    public ?string $BuildParams = 'prod';
    /** Vault file suffix + build environment. */
    public ?string $Environment = 'prod';
    public ?int $SourceId = null;
    public ?DeploySourceEntity $Source = null;

    /** Vault contents, write-only, pushed by the explicit Deploy Key Vault action. */
    public ?string $KeyVault = null;
    /** Vault file name; blank derives values.<Environment>.json. */
    public ?string $KeyVaultFile = null;

    // --- rotation ---------------------------------------------------------------------

    /**
     * `rotate` = blue/green (build into the idle slot, health-check, repoint the upstreams,
     * drain-stop the old). `restart` = server-admin's original behaviour: replace the release
     * and restart the services in place, with the downtime that implies.
     *
     * Trap: under `restart` the release folder is RootFolder itself (replaced wholesale), so a
     * first deploy onto a blue/green base like /workspace/myapp must use `rotate`.
     */
    public string $Strategy = 'rotate';

    /**
     * Unit-name prefix used to GENERATE the service/upstream rows for the common case:
     * prefix `myapp` + base port 8221 gives units `myapp-blue`:8221 and `myapp-green`:8222
     * behind upstream `myapp_app` at `/`.
     *
     * Generation only fills in an application that has none — the rows stay editable, and an app
     * that needs a second upstream or a worker adds them by hand. Storing the prefix (rather than
     * generating and forgetting) is what lets the form show what it would produce, and lets a
     * regenerate stay predictable.
     */
    public ?string $ServicePrefix = null;

    /** First port of the generated pair; the second slot takes the next one. */
    public int $BasePort = 0;
    /** Slot currently serving, as last observed. The upstream conf remains the truth. */
    public ?string $LiveSlot = null;
    public ?string $LiveCommitSha = null;
    public ?int $LastDeployedOn = null;
    /** Seconds the old slot keeps serving in-flight requests before it is stopped. */
    public int $DrainSeconds = 30;
    /** Health endpoint polled on the new slot before traffic moves. */
    public string $HealthPath = '/health';
    /**
     * Run the verification suite against the new slot before traffic moves to it.
     *
     * Health only says the process answers; this asserts the app actually works — every page
     * responds, renders and is not an error (scripts/verify). A
     * failure aborts the deploy WITHOUT rotating, so the slot that is already serving keeps serving.
     * Off by default: an application whose release has no scripts/verify would fail every deploy
     * (turn it on once the app has one).
     */
    public bool $VerifyBeforeRotate = false;
}
