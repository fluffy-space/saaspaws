<?php

namespace SaasPaws\Deploy\Deploy\Components\Models;

use SharedPaws\Models\BaseModel;

/**
 * What the "generate blue/green rows" button sends: a unit-name prefix and a first port.
 *
 * Prefix `myapp` + base port 8221 produces units `myapp-blue`:8221 and `myapp-green`:8222
 * behind upstream `myapp_app` at `/` — which is what `configs/server.php` already declares.
 * The generated rows are returned to the form, not written: they stay editable, and nothing
 * reaches the database until the operator saves.
 */
class DeployGenerateModel extends BaseModel
{
    // Deliberately loose types: the request binder fills this straight from the JSON body with
    // `StdMapper`, which assigns whatever arrived onto the property. The form's prefix is nullable
    // (`DeployApplicationModel::$ServicePrefix`) and a `model=`-bound number input sends '' once it
    // has been cleared — against `string`/`int` either one is a TypeError raised BEFORE the
    // controller runs, so the "set a prefix and a base port first" message could never be reached.
    // `DeployApplicationController::Generate()` normalises and validates both.
    public ?string $ServicePrefix = null;
    public mixed $BasePort = 0;
}
