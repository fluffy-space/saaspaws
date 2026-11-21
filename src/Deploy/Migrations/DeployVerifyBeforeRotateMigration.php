<?php

namespace SaasPaws\Deploy\Migrations;

use SaasPaws\Deploy\Deploy\Data\Repositories\DeployApplicationRepository;
use Fluffy\Data\Repositories\MigrationRepository;
use Fluffy\Migrations\BaseMigration;

/**
 * `DeployApplication.VerifyBeforeRotate` — run the verification suite against the newly started
 * slot before traffic moves onto it. The app's suite is scripts/verify/ in its release; the flag
 * is turned on per application.
 *
 * An ALTER, not a change to DeploySchemaMigration's createTable: that call is CREATE-IF-NOT-EXISTS,
 * so editing its column list would only ever reach a fresh install.
 *
 * Defaults to FALSE. Health checks say the process answers; the suite says the app works, and an
 * application whose release does not carry scripts/verify would otherwise fail every deploy — so
 * this is opted into per application rather than assumed.
 */
class DeployVerifyBeforeRotateMigration extends BaseMigration
{
    /**
     * Pinned history key: this migration ran on dev and prod as Application\Migrations\Deploy\DeployVerifyBeforeRotateMigration
     * before it moved into SaasPaws. MigrationHistory is keyed by this name, so keeping the old
     * one stops it running again (and a fresh box records it under the same key). Never change it.
     */
    public function migrationName()
    {
        return 'Application\\Migrations\\Deploy\\DeployVerifyBeforeRotateMigration';
    }

    public function __construct(
        MigrationRepository $migrationHistoryRepository,
        private DeployApplicationRepository $applications,
    ) {
        parent::__construct($migrationHistoryRepository);
    }

    public function up()
    {
        $this->applications->executeSQL(
            'ALTER TABLE "DeployApplication" ADD COLUMN IF NOT EXISTS "VerifyBeforeRotate" boolean NOT NULL DEFAULT false;'
        );
    }

    public function down()
    {
        $this->applications->executeSQL(
            'ALTER TABLE "DeployApplication" DROP COLUMN IF EXISTS "VerifyBeforeRotate";'
        );
    }
}
