<?php

namespace SaasPaws\Deploy\Deploy\Data\Entities;

use Fluffy\Data\Entities\BaseEntityMap;
use Fluffy\Data\Entities\CommonMap;

class DeployUpstreamEntityMap extends BaseEntityMap
{
    public const PROPERTY_ApplicationId = 'ApplicationId';
    public const PROPERTY_Name = 'Name';
    public const PROPERTY_Location = 'Location';
    public const PROPERTY_ConfPath = 'ConfPath';
    public const PROPERTY_DisplayOrder = 'DisplayOrder';

    public static string $Table = 'DeployUpstream';
    public static array $Indexes = [
        'IX_DeployUpstream_ApplicationId' => ['Columns' => ['ApplicationId'], 'Unique' => false],
    ];

    public static function ForeignKeys(): array
    {
        return [
            // CASCADE: an upstream has no meaning without its application.
            'Application' => [
                'Table' => DeployApplicationEntity::class,
                'Columns' => ['ApplicationId'],
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
            'Name' => CommonMap::$VarChar255,
            'Location' => ['type' => 'character varying', 'length' => 255, 'default' => "'/'", 'null' => false],
            'ConfPath' => CommonMap::$VarChar400Null,
            'DisplayOrder' => ['type' => 'integer', 'default' => '0', 'null' => false],

            'CreatedOn' => CommonMap::$MicroDateTime,
            'CreatedBy' => CommonMap::$VarChar255Null,
            'UpdatedOn' => CommonMap::$MicroDateTime,
            'UpdatedBy' => CommonMap::$VarChar255Null,
        ];
    }
}
