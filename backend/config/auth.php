<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Authentication Configuration
|--------------------------------------------------------------------------
|
| Customers and staff are separate principals with separate tables, guards,
| and password brokers. This separation is the core security property of the
| authentication phase: there is no code path by which a customer record
| becomes an administrator, because they are not the same kind of record.
|
| Guards:
|   web           -> session guard for customers (unused by the API)
|   admin         -> session guard for the Blade admin panel
|   sanctum       -> token guard for the customer storefront API
|   admin-api     -> token guard for the staff API
|
*/

return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'sanctum'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        // Session guard backing the Blade admin panel.
        'admin' => [
            'driver' => 'session',
            'provider' => 'admins',
        ],

        // Token guard for the customer storefront API.
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],

        // Token guard for the staff API. A distinct guard means a customer
        // token resolves to no user here — the provider queries a different
        // table entirely, so the token's tokenable_type cannot match.
        'admin-api' => [
            'driver' => 'sanctum',
            'provider' => 'admins',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', App\Models\User::class),
        ],

        'admins' => [
            'driver' => 'eloquent',
            'model' => App\Models\Admin::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Reset Brokers
    |--------------------------------------------------------------------------
    |
    | Separate token tables are essential, not cosmetic. If staff and customers
    | shared one, a reset token issued for a customer would be redeemable
    | against a staff account holding the same email address — a trivial
    | privilege escalation.
    |
    | `throttle` is the seconds a requester must wait before another email is
    | sent, which limits both mailbox flooding and reset-token farming.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],

        'admins' => [
            'provider' => 'admins',
            'table' => 'admin_password_reset_tokens',
            // Shorter window than customers: a staff reset token is a
            // higher-value credential, so it is valid for less time.
            'expire' => 30,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    */

    'password_timeout' => (int) env('AUTH_PASSWORD_TIMEOUT', 10800),

    /*
    |--------------------------------------------------------------------------
    | Authentication Policy
    |--------------------------------------------------------------------------
    */

    'security' => [
        // Failed attempts before an account/IP pair is locked out, and for how
        // long. Enforced by the login rate limiter.
        'max_login_attempts' => (int) env('AUTH_MAX_LOGIN_ATTEMPTS', 5),
        'lockout_seconds' => (int) env('AUTH_LOCKOUT_SECONDS', 900),

        // Staff tokens expire sooner than customer tokens; a stolen staff
        // token is worth far more.
        'customer_token_ttl_minutes' => (int) env('AUTH_CUSTOMER_TOKEN_TTL', 60 * 24 * 7),
        'admin_token_ttl_minutes' => (int) env('AUTH_ADMIN_TOKEN_TTL', 60 * 8),

        // Revoke all other tokens when a password changes, so a session
        // opened with the old password cannot outlive it.
        'revoke_tokens_on_password_change' => (bool) env('AUTH_REVOKE_TOKENS_ON_PASSWORD_CHANGE', true),
    ],

];
