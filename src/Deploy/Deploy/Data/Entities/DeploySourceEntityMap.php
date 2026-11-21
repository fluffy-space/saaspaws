<?php

namespace SaasPaws\Deploy\Deploy\Data\Entities;

use Fluffy\Data\Entities\BaseEntityMap;
use Fluffy\Data\Entities\CommonMap;

class DeploySourceEntityMap extends BaseEntityMap
{
    public const PROPERTY_Name = 'Name';
    public const PROPERTY_Type = 'Type';
    public const PROPERTY_Description = 'Description';
    public const PROPERTY_GitRepository = 'GitRepository';
    public const PROPERTY_GitUser = 'GitUser';
    public const PROPERTY_GitAccessToken = 'GitAccessToken';
    public const PROPERTY_GitBranch = 'GitBranch';
    public const PROPERTY_BuildCommand = 'BuildCommand';

    public static string $Table = 'DeploySource';
    public static array $Indexes = [
        'UX_DeploySource_Name' => ['Columns' => ['Name'], 'Unique' => true],
    ];

    public static function Columns(): array
    {
        return [
            'Id' => CommonMap::$Id,
            'Name' => CommonMap::$TextCaseInsensitive,
            'Type' => ['type' => 'character varying', 'length' => 50, 'default' => "'git'", 'null' => false],
            'Description' => CommonMap::$VarChar400Null,
            'GitRepository' => CommonMap::$VarChar400Null,
            'GitUser' => CommonMap::$VarChar255Null,
            'GitAccessToken' => CommonMap::$TextNull,
            'GitBranch' => CommonMap::$VarChar255Null,
            'BuildCommand' => CommonMap::$TextNull,

            'CreatedOn' => CommonMap::$MicroDateTime,
            'CreatedBy' => CommonMap::$VarChar255Null,
            'UpdatedOn' => CommonMap::$MicroDateTime,
            'UpdatedBy' => CommonMap::$VarChar255Null,
        ];
    }
}
