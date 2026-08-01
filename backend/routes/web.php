<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The public storefront is served by Next.js, so Laravel's web routes exist
| only for the admin panel (registered separately from routes/admin.php) and
| for infrastructure endpoints.
|
*/

Route::get('/', fn () => redirect()->route('admin.dashboard'))->name('root');
