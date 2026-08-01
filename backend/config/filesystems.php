<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Admin-uploaded brand assets (logo, favicon, banners) live on the `public`
    | disk locally and on `s3` in production. Because the settings API returns
    | absolute URLs built from the active disk, switching FILESYSTEM_DISK moves
    | asset delivery to S3/MinIO with no code or data change.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'public'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL') . '/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('AWS_BUCKET'),
            // Set for S3-compatible providers (MinIO, DigitalOcean Spaces, R2).
            'endpoint' => env('AWS_ENDPOINT'),
            // MinIO requires path-style addressing; real S3 does not.
            'use_path_style_endpoint' => (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            // Public-facing URL, which may differ from the internal endpoint
            // when the container talks to MinIO over the Docker network.
            'url' => env('AWS_URL'),
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload Constraints
    |--------------------------------------------------------------------------
    |
    | Enforced by form requests on admin uploads. Kept here so the same limits
    | can be surfaced to the admin UI without duplicating literals.
    |
    */

    'uploads' => [
        'max_image_size' => (int) env('UPLOAD_MAX_IMAGE_KB', 4096),
        'max_file_size' => (int) env('UPLOAD_MAX_FILE_KB', 10240),
        'image_mimes' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'ico'],
        'paths' => [
            'branding' => 'branding',
            'banners' => 'banners',
            'products' => 'products',
            'categories' => 'categories',
        ],
    ],

];
