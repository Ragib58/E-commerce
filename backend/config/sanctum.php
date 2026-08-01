<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from these origins authenticate via the session cookie (SPA
    | mode) rather than a bearer token. Anything not listed here must send
    | `Authorization: Bearer <token>`.
    |
    | Authentication itself is implemented in the next phase; this file sets
    | the contract now so the storefront origin is already trusted.
    |
    */

    'stateful' => explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', implode(',', [
        'localhost',
        'localhost:3000',
        'localhost:8000',
        '127.0.0.1',
        '127.0.0.1:3000',
        '127.0.0.1:8000',
        '::1',
        'frontend:3000',
    ]))),

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Token Expiration
    |--------------------------------------------------------------------------
    |
    | Minutes until an issued token expires. Null means tokens never expire —
    | avoid that in production; expired tokens are pruned by the scheduler.
    |
    */

    'expiration' => (int) env('SANCTUM_TOKEN_EXPIRATION', 60 * 24 * 7),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];
