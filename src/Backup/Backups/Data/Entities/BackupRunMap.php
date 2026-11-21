<?php

namespace SaasPaws\Backup\Backups\Data\Entities;

use Fluffy\Data\Entities\BaseEntityMap;
use Fluffy\Data\Entities\CommonMap;

class BackupRunMap extends BaseEntityMap
{
    public const PROPERTY_Id = 'Id';
    public const PROPERTY_Kind = 'Kind';
    public const PROPERTY_Status = 'Status';
    public const PROPERTY_Trigger = 'Trigger';
    public const PROPERTY_Artifact = 'Artifact';
    public const PROPERTY_SizeBytes = 'SizeBytes';
    public const PROPERTY_TablesWithData = 'TablesWithData';
    public const PROPERTY_DurationMs = 'DurationMs';
    public const PROPERTY_Encrypted = 'Encrypted';
    public const PROPERTY_Error = 'Error';
    public const PROPERTY_CreatedOn = 'CreatedOn';

    public static string $Table = 'BackupRun';

    /**
     * The only query this table serves is "most recent runs first" — for the admin list, and for
     * "when did we last succeed?". Both are ORDER BY CreatedOn DESC, so one index covers it.
     */
    public static array $Indexes = [
        'IX_BackupRun_CreatedOn' => [
            'Columns' => ['CreatedOn'],
            'Unique' => false
        ],
    ];

    public static function Columns(): array
    {
        return [
            'Id' => CommonMap::$Id,
            'Kind' => CommonMap::$VarChar255,
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
        ];
    }
}
