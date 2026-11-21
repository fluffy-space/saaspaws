<?php

namespace SaasPaws\Ops\Migrations;

use Fluffy\Migrations\BaseMigrationsContext;

/**
 * Ops module migrations (none yet: its state is settings, Redis and Swoole tables). Runs after
 * Deploy's and before Backup's. History is keyed by BaseMigration::migrationName().
 */
class MigrationsContext extends BaseMigrationsContext
{
    public function run()
    {
    }
}
