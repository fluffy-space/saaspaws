<?php

namespace SaasPaws\Backup\Migrations;

use Fluffy\Migrations\BaseMigrationsContext;

/** Backup module migrations, after Deploy's and Ops'. History is keyed by BaseMigration::migrationName(). */
class MigrationsContext extends BaseMigrationsContext
{
    public function run()
    {
        $this->runMigration(BackupRunMigration::class);
    }
}
