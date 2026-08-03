<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Variant Generation
    |--------------------------------------------------------------------------
    |
    | Ceiling on how many variants one matrix generation may create. Five
    | attributes with five values each is 3,125 rows — invariably a mis-click
    | rather than an intention, and one that would take a long time to undo by
    | hand.
    |
    */

    'max_generated_variants' => (int) env('CATALOG_MAX_GENERATED_VARIANTS', 200),

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Public catalog reads are cached under the `catalog` tag and purged by
    | CatalogChanged, so the TTL is only a backstop for a missed invalidation
    | rather than the primary freshness mechanism.
    |
    | Stock levels are deliberately NOT cached: showing "in stock" for
    | something that sold out minutes ago produces failed checkouts, which
    | costs more than the queries saved.
    |
    */

    'cache' => [
        'enabled' => (bool) env('CATALOG_CACHE_ENABLED', true),
        'ttl' => (int) env('CATALOG_CACHE_TTL', 600),
        'tag' => 'catalog',
    ],

    /*
    |--------------------------------------------------------------------------
    | Listings
    |--------------------------------------------------------------------------
    |
    | `max_per_page` is enforced server-side so a client cannot request the
    | entire catalog in one response.
    |
    */

    'listing' => [
        'per_page' => (int) env('CATALOG_PER_PAGE', 24),
        'max_per_page' => (int) env('CATALOG_MAX_PER_PAGE', 100),

        /*
         * Sorts the public API accepts, mapped to a column and direction.
         *
         * An allowlist rather than a passthrough: interpolating a client-
         * supplied column into an ORDER BY is an injection vector, and every
         * column here is indexed, so no sort can trigger a filesort over the
         * whole catalog.
         */
        'sorts' => [
            'newest' => ['column' => 'published_at', 'direction' => 'desc'],
            'oldest' => ['column' => 'published_at', 'direction' => 'asc'],
            'price_asc' => ['column' => 'price', 'direction' => 'asc'],
            'price_desc' => ['column' => 'price', 'direction' => 'desc'],
            'name_asc' => ['column' => 'name', 'direction' => 'asc'],
            'name_desc' => ['column' => 'name', 'direction' => 'desc'],
        ],

        'default_sort' => 'newest',
    ],

    /*
    |--------------------------------------------------------------------------
    | Inventory
    |--------------------------------------------------------------------------
    */

    'inventory' => [
        // Applied to new products when the operator does not set one.
        'default_low_stock_threshold' => (int) env('CATALOG_LOW_STOCK_THRESHOLD', 5),

        // Rows returned by the low-stock and out-of-stock report endpoints.
        'alert_limit' => (int) env('CATALOG_ALERT_LIMIT', 50),
    ],

];
