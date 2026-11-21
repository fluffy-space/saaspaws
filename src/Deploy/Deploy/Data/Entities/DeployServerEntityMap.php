<?php

namespace SaasPaws\Deploy\Deploy\Data\Entities;

use Fluffy\Data\Entities\BaseEntityMap;
use Fluffy\Data\Entities\CommonMap;

class DeployServerEntityMap extends BaseEntityMap
{
    public const PROPERTY_Name = 'Name';
    public const PROPERTY_Description = 'Description';
    public const PROPERTY_Host = 'Host';
    public const PROPERTY_Port = 'Port';
    public const PROPERTY_UserName = 'UserName';
    public const PROPERTY_Password = 'Password';
    public const PROPERTY_PrivateFileKey = 'PrivateFileKey';
    public const PROPERTY_PublicFileKey = 'PublicFileKey';
    public const PROPERTY_FileKeyPassphrase = 'FileKeyPassphrase';

    public static string $Table = 'DeployServer';
    public static array $Indexes = [
        'UX_DeployServer_Name' => ['Columns' => ['Name'], 'Unique' => true],
    ];

    public static function Columns(): array
    {
        return [
            'Id' => CommonMap::$Id,
            'Name' => CommonMap::$TextCaseInsensitive,
            'Description' => CommonMap::$VarChar400Null,
            'Host' => CommonMap::$VarChar255Null,
            'Port' => ['type' => 'integer', 'default' => '22', 'null' => false],
            'UserName' => CommonMap::$VarChar255Null,
            // Secrets are text: they are encrypted before storage, so longer than the plaintext.
            'Password' => CommonMap::$TextNull,
            'PrivateFileKey' => CommonMap::$TextNull,
            'PublicFileKey' => CommonMap::$TextNull,
            'FileKeyPassphrase' => CommonMap::$TextNull,

            'CreatedOn' => CommonMap::$MicroDateTime,
            'CreatedBy' => CommonMap::$VarChar255Null,
            'UpdatedOn' => CommonMap::$MicroDateTime,
            'UpdatedBy' => CommonMap::$VarChar255Null,
        ];
    }
}
