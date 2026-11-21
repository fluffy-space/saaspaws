<?php

namespace SaasPaws\Backup\Migrations;

use SaasPaws\Backup\Backups\Data\Repositories\BackupRunRepository;
use Fluffy\Data\Entities\CommonMap;
use Fluffy\Data\Repositories\MigrationRepository;
use Fluffy\Migrations\BaseMigration;

/**
 * Backup run history (copied from Urlicer, where it is two migrations: the table, then `Kind`; one
 * here because the table is new). Every attempt is recorded, successes and failures alike: a job
 * that silently stops is indistinguishable from a healthy one without this table.
 *
 * `Kind`: 'backup' (something was written) or 'verify' (something was read back). One timeline
 * rather than two tables, because the question an operator asks is "is this working", and a run
 * of backups with no verification beside them looks healthy and is not.
 */
class BackupRunMigration extends BaseMigration
{
    function __construct(MigrationRepository $MigrationHistoryRepository, private BackupRunRepository $repository)
    {
        parent::__construct($MigrationHistoryRepository);
    }

    public function up()
    {
        $this->repository->createTable(
            [
                'Id' => CommonMap::$Id,
                'Kind' => ['type' => 'character varying', 'length' => 255, 'null' => false, 'default' => "'backup'"],
                'Status' => CommonMap::$VarChar255,
                'Trigger' => CommonMap::$VarChar255,
                'Artifact' => CommonMap::$VarChar400Null,
                'SizeBytes' => CommonMap::$BigIntDefault0,
                'TablesWithData' => CommonMap::$BigIntDefault0,
                'DurationMs' => CommonMap::$BigIntDefault0,
                'Encrypted' => CommonMap::$Boolean,
                'Error' => CommonMap::$TextNull,

                'CreatedOn' => CommonMap::$MicroDateTime,
                'CreatedBy' => CommonMap::$VarChar255Null,
                'UpdatedOn' => CommonMap::$MicroDateTime,
                'UpdatedBy' => CommonMap::$VarChar255Null,
            ],
            ['Id'],
            [
                // Both queries this table serves are newest-first: the admin list, and
                // "when did we last succeed?".
                'IX_BackupRun_CreatedOn' => [
                    'Columns' => ['CreatedOn'],
                    'Unique' => false
                ]
            ],
            []
        );
    }

    public function down()
    {
        $this->repository->dropTable(true, true);
    }
}
