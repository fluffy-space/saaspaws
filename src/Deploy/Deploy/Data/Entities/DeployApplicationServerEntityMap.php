<?php

namespace SaasPaws\Deploy\Deploy\Data\Entities;

use Fluffy\Data\Entities\BaseEntityMap;
use Fluffy\Data\Entities\CommonMap;

class DeployApplicationServerEntityMap extends BaseEntityMap
{
    public const PROPERTY_ApplicationId = 'ApplicationId';
    public const PROPERTY_Application = 'Application';
    public const PROPERTY_ServerId = 'ServerId';
    public const PROPERTY_Server = 'Server';

    public static string $Table = 'DeployApplicationServer';
    public static array $Indexes = [
        // One link per pair — re-adding a server must not double-deploy it.
        'UX_DeployApplicationServer_Pair' => ['Columns' => ['ApplicationId', 'ServerId'], 'Unique' => true],
        'IX_DeployApplicationServer_ServerId' => ['Columns' => ['ServerId'], 'Unique' => false],
    ];

    public static function ForeignKeys(): array
    {
        return [
            self::PROPERTY_Application => [
                'Table' => DeployApplicationEntity::class,
                'Columns' => ['ApplicationId'],
                'References' => ['Id'],
                'OnDelete' => CommonMap::$OnDeleteCascade,
            ],
            self::PROPERTY_Server => [
                'Table' => DeployServerEntity::class,
                'Columns' => ['ServerId'],
                'References' => ['Id'],
                'OnDelete' => CommonMap::$OnDeleteCascade,
            ],
        ];
    }

    public static function Columns(): array
    {
        return [
            'Id' => CommonMap::$Id,
            'ApplicationId' => CommonMap::$BigInt,
            'ServerId' => CommonMap::$BigInt,

            'CreatedOn' => CommonMap::$MicroDateTime,
            'CreatedBy' => CommonMap::$VarChar255Null,
            'UpdatedOn' => CommonMap::$MicroDateTime,
            'UpdatedBy' => CommonMap::$VarChar255Null,
        ];
    }
}
