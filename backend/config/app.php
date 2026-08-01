<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This is the *system* name used in framework-generated strings (queue
    | names, cache prefixes, default mail sender). It is NOT the storefront's
    | company name — that is admin-managed and lives in the `settings` table.
    | Never render this value on the public site.
    |
    */

    'name' => env('APP_NAME', 'Ecommerce'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    'asset_url' => env('ASSET_URL'),

    'timezone' => env('APP_TIMEZONE', 'UTC'),

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Autoloaded Service Providers
    |--------------------------------------------------------------------------
    |
    | Application providers are registered in bootstrap/providers.php, NOT here.
    |
    | Since Laravel 11 the framework merges its own core providers (filesystem,
    | database, queue, and the rest) with whatever bootstrap/providers.php
    | returns. Declaring a `providers` key in this file *replaces* that merged
    | list rather than adding to it, which drops every core provider and leaves
    | the container unable to resolve bindings such as `files`.
    |
    */

];
