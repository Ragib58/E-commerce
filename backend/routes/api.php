<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Route Loader
|--------------------------------------------------------------------------
|
| This file registers nothing itself — it delegates to a per-version route
| file. Introducing v2 means adding routes/api/v2.php and listing the version
| in config/api.php; no existing v1 route is touched.
|
| The `api` middleware group is applied by bootstrap/app.php.
|
*/

foreach ((array) config('api.supported_versions', ['v1']) as $version) {
    $path = base_path("routes/api/{$version}.php");

    if (! file_exists($path)) {
        continue;
    }

    Route::prefix($version)
        ->name("api.{$version}.")
        ->group($path);
}
