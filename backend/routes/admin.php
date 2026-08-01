<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Panel Routes
|--------------------------------------------------------------------------
|
| Mounted at /admin with the `web` middleware group and the `admin.` name
| prefix by bootstrap/app.php. Session-based, server-rendered with Blade.
|
| Authentication and authorization middleware (`auth`, `can:access-admin`)
| are attached in the authentication phase — the User model and admin guard
| exist, but no login flow does yet, so guarding these routes now would make
| the panel unreachable.
|
| Settings, catalog, order, and payment management modules are added in later
| phases and will call the same services as the API.
|
*/

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
