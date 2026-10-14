<?php

return [
    'default' => env('CACHE_STORE', 'file'),
    // Limites de requisição não dependem de uma tabela de cache no banco escolar.
    'limiter' => 'file',
    'stores' => [
        'file' => ['driver'=>'file', 'path'=>storage_path('framework/cache/data')],
        'array' => ['driver'=>'array', 'serialize'=>false],
        'database' => ['driver'=>'database', 'connection'=>env('DB_CACHE_CONNECTION'),
            'table'=>env('DB_CACHE_TABLE','cache'), 'lock_connection'=>env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table'=>env('DB_CACHE_LOCK_TABLE','cache_locks')],
    ],
    'prefix' => 'etec_hub_cache_',
];
