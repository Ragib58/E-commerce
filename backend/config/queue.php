<?php

declare(strict_types=1);

return [

    'default' => env('QUEUE_CONNECTION', 'redis'),

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'queue'),
            'queue' => env('REDIS_QUEUE', 'default'),
            // Must exceed the longest job's runtime, otherwise a slow job is
            // released back onto the queue and runs twice concurrently.
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 120),
            'block_for' => 5,
            // Dispatch only after the surrounding DB transaction commits, so a
            // worker can never pick up a job referencing an uncommitted row.
            'after_commit' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Priorities
    |--------------------------------------------------------------------------
    |
    | Workers consume these left to right. Keeping notifications ahead of
    | bulk work stops a large export from delaying order confirmation emails.
    |
    */

    'priorities' => [
        'high',
        'default',
        'notifications',
        'low',
    ],

    'batching' => [
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'job_batches',
    ],

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],

];
