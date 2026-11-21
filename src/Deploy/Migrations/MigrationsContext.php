<?php

namespace SaasPaws\Deploy\Migrations;

use Fluffy\Migrations\BaseMigrationsContext;

/**
 * Deploy module migrations, run after Fluffy core and Paws and before the app's. History is keyed by
 * BaseMigration::migrationName(); the two below keep their pre-module keys (see each class).
 */
class MigrationsContext extends BaseMigrationsContext
{
    public function run()
    {
        // The tables exist on every box; the deploy itself is dev-only.
        $this->runMigration(DeploySchemaMigration::class);
        $this->runMigration(DeployVerifyBeforeRotateMigration::class);
    }
}
