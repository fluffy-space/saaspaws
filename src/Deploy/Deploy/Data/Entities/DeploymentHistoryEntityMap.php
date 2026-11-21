<?php

namespace SaasPaws\Deploy\Deploy\Data\Entities;

use Fluffy\Data\Entities\BaseEntityMap;
use Fluffy\Data\Entities\CommonMap;

class DeploymentHistoryEntityMap extends BaseEntityMap
{
    public const PROPERTY_ApplicationId = 'ApplicationId';
    public const PROPERTY_ServerId = 'ServerId';
    public const PROPERTY_Server = 'Server';
    public const PROPERTY_SourceId = 'SourceId';
    public const PROPERTY_Source = 'Source';
    public const PROPERTY_Kind = 'Kind';
    public const PROPERTY_StartedOn = 'StartedOn';
    public const PROPERTY_CompletedOn = 'CompletedOn';
    public const PROPERTY_IsSuccessful = 'IsSuccessful';
    public const PROPERTY_Error = 'Error';
    public const PROPERTY_Logs = 'Logs';
    public const PROPERTY_FromSlot = 'FromSlot';
    public const PROPERTY_ToSlot = 'ToSlot';
    public const PROPERTY_CommitSha = 'CommitSha';
    public const PROPERTY_CommitMessage = 'CommitMessage';
    public const PROPERTY_TriggeredByName = 'TriggeredByName';

    public static string $Table = 'DeploymentHistory';
    public static array $Indexes = [
        'IX_DeploymentHistory_App_Started' => ['Columns' => ['ApplicationId', 'StartedOn'], 'Unique' => false],
    ];

    public static function ForeignKeys(): array
    {
        return [
            // RESTRICT everywhere: history is the audit trail, so what it points at cannot be
            // deleted out from under it.
            'Application' => [
                'Table' => DeployApplicationEntity::class,
                'Columns' => ['ApplicationId'],
                'References' => ['Id'],
                'OnDelete' => CommonMap::$OnDeleteRestrict,
            ],
            self::PROPERTY_Server => [
                'Table' => DeployServerEntity::class,
                'Columns' => ['ServerId'],
                'References' => ['Id'],
                'OnDelete' => CommonMap::$OnDeleteRestrict,
            ],
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
            'ApplicationId' => CommonMap::$BigInt,
            'ServerId' => CommonMap::$BigInt,
            'SourceId' => CommonMap::$BigIntNull,
            'Kind' => ['type' => 'character varying', 'length' => 50, 'default' => "'deploy'", 'null' => false],
            'StartedOn' => CommonMap::$MicroDateTimeNull,
            'CompletedOn' => CommonMap::$MicroDateTimeNull,
            // NULLABLE: null = still running, false = ran and failed.
            'IsSuccessful' => ['type' => 'boolean', 'null' => true],
            'Error' => CommonMap::$TextNull,
            'Logs' => CommonMap::$TextNull,
            'FromSlot' => CommonMap::$VarChar255Null,
            'ToSlot' => CommonMap::$VarChar255Null,
            'CommitSha' => CommonMap::$VarChar255Null,
            'CommitMessage' => CommonMap::$VarChar400Null,
            'TriggeredByName' => CommonMap::$VarChar255Null,

            'CreatedOn' => CommonMap::$MicroDateTime,
            'CreatedBy' => CommonMap::$VarChar255Null,
            'UpdatedOn' => CommonMap::$MicroDateTime,
            'UpdatedBy' => CommonMap::$VarChar255Null,
        ];
    }
}
