<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Metadata storage location
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default metadata storage repository that
    | should be used. This defaults to 'json' but you can use 'database'
    | (recommended) or 'memory' for testing.
    |
    */

    'metadata' => 'json',

    /*
    |--------------------------------------------------------------------------
    | Database connection
    |--------------------------------------------------------------------------
    |
    | Specify the database connection you wish to use. Defaults to the
    | application default connection.
    |
    */
    'database' => [
        'connection' => env('DB_CONNECTION', 'mysql'),
    ],

    /*
    |--------------------------------------------------------------------------
    | JSON Storage
    |--------------------------------------------------------------------------
    |
    | Path to where the JSON data will be stored after it is serialized.
    |
    */
    'json'     => [
        'storage_path' => 'file-storage-metadata.json',
    ],

];
