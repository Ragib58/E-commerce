<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | API Versioning
    |--------------------------------------------------------------------------
    |
    | Versioning is URI-based: /api/v1/..., /api/v2/... Each supported version
    | maps to a route file in routes/api/. Adding v2 means adding the file and
    | listing it here — no change to existing v1 routes.
    |
    */

    'default_version' => env('API_DEFAULT_VERSION', 'v1'),

    'supported_versions' => ['v1'],

    'deprecated_versions' => [
        // 'v1' => '2027-01-01',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Attempts allowed per minute, per resolved key (user id when authenticated,
    | client IP otherwise). Registered as named limiters in AppServiceProvider.
    |
    */

    'rate_limits' => [
        'public' => (int) env('API_RATE_LIMIT_PUBLIC', 60),
        'authenticated' => (int) env('API_RATE_LIMIT_AUTH', 120),
        'health' => (int) env('API_RATE_LIMIT_HEALTH', 30),

        /*
         * Credential endpoints.
         *
         * Two limits apply to each: a tight one keyed on email+IP, and a
         * wider one keyed on IP alone that catches an attacker rotating the
         * email to stay under the first. Staff limits are tighter still —
         * there are few legitimate staff, and a compromised staff account
         * costs far more than a compromised customer account.
         */
        'auth' => (int) env('API_RATE_LIMIT_AUTH_ATTEMPTS', 5),
        'auth_per_ip' => (int) env('API_RATE_LIMIT_AUTH_PER_IP', 20),
        'admin_auth' => (int) env('API_RATE_LIMIT_ADMIN_AUTH', 5),
        'admin_auth_per_ip' => (int) env('API_RATE_LIMIT_ADMIN_AUTH_PER_IP', 10),

        // Requests per window, in minutes. Every one of these sends an email.
        'password_reset' => (int) env('API_RATE_LIMIT_PASSWORD_RESET', 3),
        'password_reset_window' => (int) env('API_RATE_LIMIT_PASSWORD_RESET_WINDOW', 15),
        'verification' => (int) env('API_RATE_LIMIT_VERIFICATION', 5),
        'verification_window' => (int) env('API_RATE_LIMIT_VERIFICATION_WINDOW', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | `max_per_page` is enforced server-side so a client cannot request an
    | unbounded page size and exhaust memory.
    |
    */

    'pagination' => [
        'per_page' => (int) env('API_PER_PAGE', 20),
        'max_per_page' => (int) env('API_MAX_PER_PAGE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend Revalidation
    |--------------------------------------------------------------------------
    |
    | When admin-managed content changes, Laravel pings the Next.js revalidation
    | endpoint so the storefront's ISR cache is invalidated without a redeploy.
    | The secret must match REVALIDATION_SECRET in the frontend environment.
    |
    */

    'revalidation' => [
        'enabled' => (bool) env('FRONTEND_REVALIDATION_ENABLED', true),
        'url' => env('FRONTEND_REVALIDATION_URL', 'http://frontend:3000/api/revalidate'),
        'secret' => env('FRONTEND_REVALIDATION_SECRET'),
        'timeout' => (int) env('FRONTEND_REVALIDATION_TIMEOUT', 5),
    ],

];
