<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\SettingsController;
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

/*
 * Dynamic store settings — branding, theme, SEO, contact, social, business.
 *
 * The keys are constrained to the dot-namespaced format used by the settings
 * table so a media route cannot swallow an extra path segment.
 *
 * These will gain `auth` + `permission:manage_settings` middleware alongside
 * the rest of the panel when the Blade session guard is attached; the API
 * equivalents at /api/v1/admin/settings are already permission-gated.
 */
Route::prefix('settings')->name('settings.')->group(function (): void {
    Route::get('/', [SettingsController::class, 'index'])->name('index');

    Route::put('/{group}', [SettingsController::class, 'update'])
        ->where('group', '[a-z_]+')
        ->name('update');

    Route::post('/media/{key}', [SettingsController::class, 'uploadMedia'])
        ->where('key', '[a-z0-9_]+\.[a-z0-9_]+')
        ->name('media.upload');

    Route::delete('/media/{key}', [SettingsController::class, 'destroyMedia'])
        ->where('key', '[a-z0-9_]+\.[a-z0-9_]+')
        ->name('media.destroy');

    Route::post('/cache/flush', [SettingsController::class, 'flushCache'])->name('cache.flush');
});
