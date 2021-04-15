<?php

return [

    // What metadata driver?
    'metadata' => 'json',

    'database' => [
        'connection' => env('DB_CONNECTION', 'mysql'),
    ],

    'json' => [
        'storage_path' => 'file-storage-metadata.json',
    ],

];
