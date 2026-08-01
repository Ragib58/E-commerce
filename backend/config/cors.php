<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| The storefront (Next.js) and the API are served from different origins in
| development (:3000 vs :8000) and may be in production too. Allowed origins
| are read from the environment rather than wildcarded, because
| `supports_credentials: true` and `allowed_origins: ['*']` are mutually
| incompatible under the CORS spec — browsers reject the combination.
|
| Set CORS_ALLOWED_ORIGINS to a comma-separated list of exact origins.
|
*/

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

if ($origins === []) {
    $origins = array_values(array_unique(array_filter([
        env('FRONTEND_URL', 'http://localhost:3000'),
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ])));
}

return [

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'up',
    ],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins,

    // Regex patterns for dynamic origins (preview deployments, subdomains).
    // Example: '/^https:\/\/.*\.vercel\.app$/'
    'allowed_origins_patterns' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', ''))
    ))),

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-XSRF-TOKEN',
        'X-CSRF-TOKEN',
        'X-API-Version',
        'X-Request-Id',
        'Origin',
    ],

    // Headers the browser is permitted to read from the response.
    'exposed_headers' => [
        'X-API-Version',
        'X-API-Supported-Versions',
        'X-Request-Id',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'Retry-After',
    ],

    // Cache preflight results for 24h to cut OPTIONS round-trips.
    'max_age' => (int) env('CORS_MAX_AGE', 86400),

    // Required for Sanctum cookie-based auth in the upcoming auth phase.
    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', true),

];
