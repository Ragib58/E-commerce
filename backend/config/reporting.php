<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Dashboard metrics and charts aggregate across the whole orders table.
    | Recomputing them on every panel load is the single most expensive thing
    | the admin API can do, and a dashboard is the page staff leave open all
    | day — so results are cached under the `reports` tag and purged by
    | ReportingDataChanged when an order, payment, or stock level moves.
    |
    | Two TTLs, because two questions age differently. A metric answering
    | "how many orders are pending right now" is worth recomputing often; a
    | chart of last year's revenue by month does not change until midnight.
    | The service picks between them per query rather than applying one
    | compromise TTL to both.
    |
    */

    'cache' => [
        'enabled' => (bool) env('REPORTING_CACHE_ENABLED', true),

        // Live figures: today's sales, pending order count, low stock.
        'ttl' => (int) env('REPORTING_CACHE_TTL', 300),

        // Historical aggregates over closed periods, which cannot change.
        'long_ttl' => (int) env('REPORTING_CACHE_LONG_TTL', 3600),

        'tag' => 'reports',
    ],

    /*
    |--------------------------------------------------------------------------
    | Query bounds
    |--------------------------------------------------------------------------
    |
    | Every ceiling here exists to stop one request from becoming a table scan
    | the database cannot finish. They are not user-facing product limits: a
    | report that legitimately needs more rows is an export, which streams,
    | rather than a JSON response that has to be built in memory first.
    |
    */

    'limits' => [
        // Rows per page in a report listing.
        'per_page' => (int) env('REPORTING_PER_PAGE', 25),
        'max_per_page' => (int) env('REPORTING_MAX_PER_PAGE', 100),

        // Ranked chart series — top products, top categories.
        'top_n' => (int) env('REPORTING_TOP_N', 10),
        'max_top_n' => (int) env('REPORTING_MAX_TOP_N', 50),

        /*
         * Ceiling on rows a single export may contain.
         *
         * Exports stream rather than buffer, so this is not a memory limit —
         * it is a bound on how long one request may hold a database cursor
         * open. A range that exceeds it is a request to narrow the range,
         * which the error says explicitly.
         */
        'max_export_rows' => (int) env('REPORTING_MAX_EXPORT_ROWS', 50000),

        /*
         * Ceiling on the span of a custom date range, in days.
         *
         * A five-year range grouped by day is 1,800 buckets nobody reads on a
         * chart, and the query behind it scans most of the orders table.
         */
        'max_range_days' => (int) env('REPORTING_MAX_RANGE_DAYS', 1096),
    ],

    /*
    |--------------------------------------------------------------------------
    | Revenue recognition
    |--------------------------------------------------------------------------
    |
    | Which orders count as sales.
    |
    | A dashboard that counts every row in `orders` as revenue reports money
    | the store never received — a cancelled order and a failed payment are
    | both rows. Sales figures therefore count only orders whose payment
    | actually settled, and net out what was refunded.
    |
    | Order *counts* deliberately do not apply this filter: "total orders" means
    | every order placed, including the cancelled ones, because that is the
    | question an operations dashboard is asking.
    |
    */

    'revenue' => [
        /*
         * Payment statuses that count toward sales.
         *
         * `partially_refunded` is included because the unrefunded remainder is
         * real revenue; `refunded_total` is subtracted from the figure rather
         * than the whole order being dropped.
         */
        'paid_statuses' => ['paid', 'partially_refunded'],

        /*
         * Order statuses excluded from revenue regardless of payment status.
         *
         * A cancelled order that was paid before cancellation is money owed
         * back, not money earned; it will show as a refund once processed.
         */
        'excluded_statuses' => ['cancelled', 'refunded'],
    ],

];
