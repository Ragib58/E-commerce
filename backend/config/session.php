<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Session Driver
    |--------------------------------------------------------------------------
    |
    | Sessions back the Blade admin panel. Redis DB 1 keeps them isolated from
    | the cache store, so clearing the cache never logs administrators out.
    |
    */

    'driver' => env('SESSION_DRIVER', 'redis'),

    'lifetime' => (int) env('SESSION_LIFETIME', 120),

    'expire_on_close' => (bool) env('SESSION_EXPIRE_ON_CLOSE', false),

    'encrypt' => (bool) env('SESSION_ENCRYPT', true),

    'files' => storage_path('framework/sessions'),

    'connection' => env('SESSION_CONNECTION', 'session'),

    'table' => env('SESSION_TABLE', 'sessions'),

    'store' => env('SESSION_STORE'),

    'lottery' => [2, 100],

    'cookie' => env(
        'SESSION_COOKIE',
        Str::slug((string) env('APP_NAME', 'ecommerce'), '_') . '_session'
    ),

    'path' => env('SESSION_PATH', '/'),

    // Leading-dot domain (".example.com") enables cookie sharing between the
    // API and the storefront subdomain for Sanctum SPA auth.
    'domain' => env('SESSION_DOMAIN'),

    'secure' => env('SESSION_SECURE_COOKIE'),

    'http_only' => (bool) env('SESSION_HTTP_ONLY', true),

    // 'lax' permits the top-level GET navigations Sanctum's CSRF flow needs
    // while still blocking cross-site POST. Use 'none' only over HTTPS.
    'same_site' => env('SESSION_SAME_SITE', 'lax'),

    'partitioned' => (bool) env('SESSION_PARTITIONED_COOKIE', false),

];
