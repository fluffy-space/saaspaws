<?php

namespace SaasPaws\Deploy\Deploy\Data\Entities;

use Fluffy\Data\Entities\BaseEntityMap;
use Fluffy\Data\Entities\CommonMap;

class DeployServiceEntityMap extends BaseEntityMap
{
    public const PROPERTY_ApplicationId = 'ApplicationId';
    public const PROPERTY_Name = 'Name';
    public const PROPERTY_Slot = 'Slot';
    public const PROPERTY_Port = 'Port';
    public const PROPERTY_UpstreamId = 'UpstreamId';
    public const PROPERTY_DisplayOrder = 'DisplayOrder';

    public static string $Table = 'DeployService';
    public static array $Indexes = [
        'IX_DeployService_ApplicationId' => ['Columns' => ['ApplicationId'], 'Unique' => false],
        'IX_DeployService_UpstreamId' => ['Columns' => ['UpstreamId'], 'Unique' => false],
    ];

    public static function ForeignKeys(): array
    {
        return [
            'Application' => [
                'Table' => DeployApplicationEntity::class,
                'Columns' => ['ApplicationId'],
                'References' => ['Id'],
                'OnDelete' => CommonMap::$OnDeleteCascade,
            ],
            // SET NULL, not CASCADE: deleting an upstream must not delete the unit that served
            // it — the service still exists on the box and still has to be restarted.
            'Upstream' => [
                'Table' => DeployUpstreamEntity::class,
                'Columns' => ['UpstreamId'],
                'References' => ['Id'],
                'OnDelete' => CommonMap::$OnDeleteSetNull,
            ],
        ];
    }

    public static function Columns(): array
    {
        return [
            'Id' => CommonMap::$Id,
            'ApplicationId' => CommonMap::$BigInt,
            'Name' => CommonMap::$VarChar255,
            'Slot' => CommonMap::$VarChar255Null,
            'Port' => ['type' => 'integer', 'default' => '0', 'null' => false],
            'UpstreamId' => CommonMap::$BigIntNull,
            'DisplayOrder' => ['type' => 'integer', 'default' => '0', 'null' => false],

            'CreatedOn' => CommonMap::$MicroDateTime,
            'CreatedBy' => CommonMap::$VarChar255Null,
            'UpdatedOn' => CommonMap::$MicroDateTime,
            'UpdatedBy' => CommonMap::$VarChar255Null,
        ];
    }
}
