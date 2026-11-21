<?php

namespace SaasPaws\Deploy\Migrations;

use SaasPaws\Deploy\Deploy\Data\Entities\DeployApplicationEntityMap;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployServerEntityMap;
use SaasPaws\Deploy\Deploy\Data\Entities\DeploySourceEntityMap;
use SaasPaws\Deploy\Deploy\Data\Entities\DeployUpstreamEntityMap;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeployApplicationRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeployApplicationServerRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeployServerRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeployServiceRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeploySourceRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeployUpstreamRepository;
use SaasPaws\Deploy\Deploy\Data\Repositories\DeploymentHistoryRepository;
use Fluffy\Data\Entities\CommonMap;
use Fluffy\Data\Repositories\MigrationRepository;
use Fluffy\Migrations\BaseMigration;

/**
 * The deployment schema, shaped after `server-admin` (Server / SourceCode / Application /
 * ApplicationServer / DeploymentHistory) with two extensions:
 *
 *  - **DeployService** — server-admin had one `ServiceName`; an application here has several
 *    systemd units, each with a `Slot` (blue/green, or none for a worker) and its own `Port`.
 *  - **DeployUpstream** — server-admin had one `Upstream` + `UpstreamPort`; here an application
 *    has one upstream per location (`/`, `/api`, …), each repointed independently on rotation.
 *
 * Created in FK order: Server, Source, Application, Upstream, Service, ApplicationServer,
 * DeploymentHistory. Columns are spelled out inline (immutable snapshot) per the
 * migration-explicit-schema rule.
 */
class DeploySchemaMigration extends BaseMigration
{
    /**
     * Pinned history key: this migration ran on dev and prod as Application\Migrations\Deploy\DeploySchemaMigration
     * before it moved into SaasPaws. MigrationHistory is keyed by this name, so keeping the old
     * one stops it running again (and a fresh box records it under the same key). Never change it.
     */
    public function migrationName()
    {
        return 'Application\\Migrations\\Deploy\\DeploySchemaMigration';
    }

    function __construct(
        MigrationRepository $MigrationHistoryRepository,
        private DeployServerRepository $servers,
        private DeploySourceRepository $sources,
        private DeployApplicationRepository $applications,
        private DeployUpstreamRepository $upstreams,
        private DeployServiceRepository $services,
        private DeployApplicationServerRepository $links,
        private DeploymentHistoryRepository $history,
    ) {
        parent::__construct($MigrationHistoryRepository);
    }

    public function up()
    {
        $audit = [
            'CreatedOn' => CommonMap::$MicroDateTime,
            'CreatedBy' => CommonMap::$VarChar255Null,
            'UpdatedOn' => CommonMap::$MicroDateTime,
            'UpdatedBy' => CommonMap::$VarChar255Null,
        ];

        $this->servers->createTable(
            array_merge([
                'Id' => CommonMap::$Id,
                'Name' => CommonMap::$TextCaseInsensitive,
                'Description' => CommonMap::$VarChar400Null,
                'Host' => CommonMap::$VarChar255Null,
                'Port' => ['type' => 'integer', 'default' => '22', 'null' => false],
                'UserName' => CommonMap::$VarChar255Null,
                'Password' => CommonMap::$TextNull,
                'PrivateFileKey' => CommonMap::$TextNull,
                'PublicFileKey' => CommonMap::$TextNull,
                'FileKeyPassphrase' => CommonMap::$TextNull,
            ], $audit),
            ['Id'],
            ['UX_DeployServer_Name' => ['Columns' => ['Name'], 'Unique' => true]],
            []
        );

        $this->sources->createTable(
            array_merge([
                'Id' => CommonMap::$Id,
                'Name' => CommonMap::$TextCaseInsensitive,
                'Type' => ['type' => 'character varying', 'length' => 50, 'default' => "'git'", 'null' => false],
                'Description' => CommonMap::$VarChar400Null,
                'GitRepository' => CommonMap::$VarChar400Null,
                'GitUser' => CommonMap::$VarChar255Null,
                'GitAccessToken' => CommonMap::$TextNull,
                'GitBranch' => CommonMap::$VarChar255Null,
                'BuildCommand' => CommonMap::$TextNull,
            ], $audit),
            ['Id'],
            ['UX_DeploySource_Name' => ['Columns' => ['Name'], 'Unique' => true]],
            []
        );

        $this->applications->createTable(
            array_merge([
                'Id' => CommonMap::$Id,
                'Name' => CommonMap::$TextCaseInsensitive,
                'Description' => CommonMap::$TextNull,
                'RootFolder' => CommonMap::$VarChar400Null,
                'Subfolder' => CommonMap::$VarChar255Null,
                'Domain' => CommonMap::$VarChar255Null,
                'BuildParams' => CommonMap::$TextNull,
                'Environment' => ['type' => 'character varying', 'length' => 50, 'default' => "'prod'", 'null' => false],
                'SourceId' => CommonMap::$BigIntNull,
                'KeyVault' => CommonMap::$TextNull,
                'KeyVaultFile' => CommonMap::$VarChar255Null,
                'Strategy' => ['type' => 'character varying', 'length' => 20, 'default' => "'rotate'", 'null' => false],
                // Prefix + base port generate the service/upstream rows for the common case.
                'ServicePrefix' => CommonMap::$VarChar255Null,
                'BasePort' => ['type' => 'integer', 'default' => '0', 'null' => false],
                'LiveSlot' => CommonMap::$VarChar255Null,
                'LiveCommitSha' => CommonMap::$VarChar255Null,
                'LastDeployedOn' => CommonMap::$MicroDateTimeNull,
                'DrainSeconds' => ['type' => 'integer', 'default' => '30', 'null' => false],
                'HealthPath' => ['type' => 'character varying', 'length' => 255, 'default' => "'/health'", 'null' => false],
            ], $audit),
            ['Id'],
            ['UX_DeployApplication_Name' => ['Columns' => ['Name'], 'Unique' => true]],
            [[
                'Table' => DeploySourceEntityMap::class,
                'Columns' => ['SourceId'],
                'References' => ['Id'],
                'OnDelete' => CommonMap::$OnDeleteRestrict,
            ]]
        );

        $this->upstreams->createTable(
            array_merge([
                'Id' => CommonMap::$Id,
                'ApplicationId' => CommonMap::$BigInt,
                'Name' => CommonMap::$VarChar255,
                'Location' => ['type' => 'character varying', 'length' => 255, 'default' => "'/'", 'null' => false],
                'ConfPath' => CommonMap::$VarChar400Null,
                'DisplayOrder' => ['type' => 'integer', 'default' => '0', 'null' => false],
            ], $audit),
            ['Id'],
            ['IX_DeployUpstream_ApplicationId' => ['Columns' => ['ApplicationId'], 'Unique' => false]],
            [[
                'Table' => DeployApplicationEntityMap::class,
                'Columns' => ['ApplicationId'],
                'References' => ['Id'],
                'OnDelete' => CommonMap::$OnDeleteCascade,
            ]]
        );

        $this->services->createTable(
            array_merge([
                'Id' => CommonMap::$Id,
                'ApplicationId' => CommonMap::$BigInt,
                'Name' => CommonMap::$VarChar255,
                'Slot' => CommonMap::$VarChar255Null,
                'Port' => ['type' => 'integer', 'default' => '0', 'null' => false],
                'UpstreamId' => CommonMap::$BigIntNull,
                'DisplayOrder' => ['type' => 'integer', 'default' => '0', 'null' => false],
            ], $audit),
            ['Id'],
            [
                'IX_DeployService_ApplicationId' => ['Columns' => ['ApplicationId'], 'Unique' => false],
                'IX_DeployService_UpstreamId' => ['Columns' => ['UpstreamId'], 'Unique' => false],
            ],
            [
                [
                    'Table' => DeployApplicationEntityMap::class,
                    'Columns' => ['ApplicationId'],
                    'References' => ['Id'],
                    'OnDelete' => CommonMap::$OnDeleteCascade,
                ],
                [
                    // SET NULL: dropping an upstream must not delete the unit that served it.
                    'Table' => DeployUpstreamEntityMap::class,
                    'Columns' => ['UpstreamId'],
                    'References' => ['Id'],
                    'OnDelete' => CommonMap::$OnDeleteSetNull,
                ],
            ]
        );

        $this->links->createTable(
            array_merge([
                'Id' => CommonMap::$Id,
                'ApplicationId' => CommonMap::$BigInt,
                'ServerId' => CommonMap::$BigInt,
            ], $audit),
            ['Id'],
            [
                'UX_DeployApplicationServer_Pair' => ['Columns' => ['ApplicationId', 'ServerId'], 'Unique' => true],
                'IX_DeployApplicationServer_ServerId' => ['Columns' => ['ServerId'], 'Unique' => false],
            ],
            [
                [
                    'Table' => DeployApplicationEntityMap::class,
                    'Columns' => ['ApplicationId'],
                    'References' => ['Id'],
                    'OnDelete' => CommonMap::$OnDeleteCascade,
                ],
                [
                    'Table' => DeployServerEntityMap::class,
                    'Columns' => ['ServerId'],
                    'References' => ['Id'],
                    'OnDelete' => CommonMap::$OnDeleteCascade,
                ],
            ]
        );

        $this->history->createTable(
            array_merge([
                'Id' => CommonMap::$Id,
                'ApplicationId' => CommonMap::$BigInt,
                'ServerId' => CommonMap::$BigInt,
                'SourceId' => CommonMap::$BigIntNull,
                'Kind' => ['type' => 'character varying', 'length' => 50, 'default' => "'deploy'", 'null' => false],
                'StartedOn' => CommonMap::$MicroDateTimeNull,
                'CompletedOn' => CommonMap::$MicroDateTimeNull,
                // Null = still running; false = ran and failed.
                'IsSuccessful' => ['type' => 'boolean', 'null' => true],
                'Error' => CommonMap::$TextNull,
                'Logs' => CommonMap::$TextNull,
                'FromSlot' => CommonMap::$VarChar255Null,
                'ToSlot' => CommonMap::$VarChar255Null,
                'CommitSha' => CommonMap::$VarChar255Null,
                'CommitMessage' => CommonMap::$VarChar400Null,
                'TriggeredByName' => CommonMap::$VarChar255Null,
            ], $audit),
            ['Id'],
            ['IX_DeploymentHistory_App_Started' => ['Columns' => ['ApplicationId', 'StartedOn'], 'Unique' => false]],
            [
                [
                    'Table' => DeployApplicationEntityMap::class,
                    'Columns' => ['ApplicationId'],
                    'References' => ['Id'],
                    'OnDelete' => CommonMap::$OnDeleteRestrict,
                ],
                [
                    'Table' => DeployServerEntityMap::class,
                    'Columns' => ['ServerId'],
                    'References' => ['Id'],
                    'OnDelete' => CommonMap::$OnDeleteRestrict,
                ],
                [
                    'Table' => DeploySourceEntityMap::class,
                    'Columns' => ['SourceId'],
                    'References' => ['Id'],
                    'OnDelete' => CommonMap::$OnDeleteRestrict,
                ],
            ]
        );
    }

    public function down()
    {
        // Reverse of creation order — the FKs make any other order fail.
        $this->history->dropTable(true, true);
        $this->links->dropTable(true, true);
        $this->services->dropTable(true, true);
        $this->upstreams->dropTable(true, true);
        $this->applications->dropTable(true, true);
        $this->sources->dropTable(true, true);
        $this->servers->dropTable(true, true);
    }
}
