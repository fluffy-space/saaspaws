<?php

namespace SaasPaws\Deploy\Deploy\Data\Entities;

use Fluffy\Data\Entities\BaseEntityMap;
use Fluffy\Data\Entities\CommonMap;

class DeployApplicationEntityMap extends BaseEntityMap
{
    public const PROPERTY_Name = 'Name';
    public const PROPERTY_Description = 'Description';
    public const PROPERTY_RootFolder = 'RootFolder';
    public const PROPERTY_Subfolder = 'Subfolder';
    public const PROPERTY_Domain = 'Domain';
    public const PROPERTY_BuildParams = 'BuildParams';
    public const PROPERTY_Environment = 'Environment';
    public const PROPERTY_SourceId = 'SourceId';
    public const PROPERTY_Source = 'Source';
    public const PROPERTY_KeyVault = 'KeyVault';
    public const PROPERTY_KeyVaultFile = 'KeyVaultFile';
    public const PROPERTY_Strategy = 'Strategy';
    public const PROPERTY_ServicePrefix = 'ServicePrefix';
    public const PROPERTY_BasePort = 'BasePort';
    public const PROPERTY_LiveSlot = 'LiveSlot';
    public const PROPERTY_LiveCommitSha = 'LiveCommitSha';
    public const PROPERTY_LastDeployedOn = 'LastDeployedOn';
    public const PROPERTY_DrainSeconds = 'DrainSeconds';
    public const PROPERTY_HealthPath = 'HealthPath';
    public const PROPERTY_VerifyBeforeRotate = 'VerifyBeforeRotate';

    public static string $Table = 'DeployApplication';
    public static array $Indexes = [
        'UX_DeployApplication_Name' => ['Columns' => ['Name'], 'Unique' => true],
    ];

    public static function ForeignKeys(): array
    {
        return [
            // RESTRICT: a source still wired to an application must not vanish under it.
            self::PROPERTY_Source => [
                'Table' => DeploySourceEntity::class,
                'Columns' => ['SourceId'],
                'References' => ['Id'],
                'OnDelete' => CommonMap::$OnDeleteRestrict,
            ],
        ];
    }

    public static function Columns(): array
    {
        return [
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
            'ServicePrefix' => CommonMap::$VarChar255Null,
            'BasePort' => ['type' => 'integer', 'default' => '0', 'null' => false],
            'LiveSlot' => CommonMap::$VarChar255Null,
            'LiveCommitSha' => CommonMap::$VarChar255Null,
            'LastDeployedOn' => CommonMap::$MicroDateTimeNull,
            'DrainSeconds' => ['type' => 'integer', 'default' => '30', 'null' => false],
            'HealthPath' => ['type' => 'character varying', 'length' => 255, 'default' => "'/health'", 'null' => false],
            'VerifyBeforeRotate' => ['type' => 'boolean', 'default' => 'false', 'null' => false],

            'CreatedOn' => CommonMap::$MicroDateTime,
            'CreatedBy' => CommonMap::$VarChar255Null,
            'UpdatedOn' => CommonMap::$MicroDateTime,
            'UpdatedBy' => CommonMap::$VarChar255Null,
        ];
    }
}
