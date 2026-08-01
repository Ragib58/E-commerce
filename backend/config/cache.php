<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | Redis is required, not merely preferred: the settings layer relies on
    | Cache::tags() for event-driven invalidation, and tags silently no-op on
    | the `file` and `array` drivers — a settings change would then serve stale
    | branding until the TTL expired. Do not downgrade this in production.
    |
    */

    'default' => env('CACHE_STORE', 'redis'),

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE'),
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    */

    'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'ecommerce'), '_') . '_cache_'),

    /*
    |--------------------------------------------------------------------------
    | Application Cache TTLs (seconds)
    |--------------------------------------------------------------------------
    |
    | Referenced by services rather than hardcoded at call sites, so cache
    | behaviour is tunable per environment. Settings get a long TTL because
    | invalidation is event-driven — the TTL is only a backstop.
    |
    */

    'ttl' => [
        'settings' => (int) env('CACHE_TTL_SETTINGS', 86400),
        'menus' => (int) env('CACHE_TTL_MENUS', 86400),
        'catalog' => (int) env('CACHE_TTL_CATALOG', 3600),
        'health' => (int) env('CACHE_TTL_HEALTH', 10),

        /*
         * Resolved admin permission sets.
         *
         * An authorization check runs on nearly every admin request, often
         * several times, and resolving it walks two pivot tables. The TTL is
         * only a backstop — the cache is flushed explicitly whenever roles or
         * permissions change, so a revocation takes effect immediately rather
         * than after an hour.
         */
        'permissions' => (int) env('CACHE_TTL_PERMISSIONS', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Tags
    |--------------------------------------------------------------------------
    |
    | Centralised so a listener and the service that writes the entry cannot
    | drift apart on the tag string.
    |
    */

    'tags' => [
        'settings' => 'settings',
        'menus' => 'menus',
        'catalog' => 'catalog',
    ],

];
