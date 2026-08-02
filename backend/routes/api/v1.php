<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AdminAuthController;
use App\Http\Controllers\Api\V1\Admin\AdminManagementController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SettingsManagementController;
use App\Http\Controllers\Api\V1\Auth\CustomerAuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\SettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 Routes
|--------------------------------------------------------------------------
|
| Mounted at /api/v1 by routes/api.php.
|
| Two authentication realms, deliberately separate:
|
|   /auth/*        customers, guard `sanctum`,   provider `users`
|   /admin/auth/*  staff,     guard `admin-api`, provider `admins`
|
| The guards resolve different models from different tables, so a customer
| token cannot authenticate against an admin route even before permission
| checks run.
|
| Catalog, cart, orders, and payments are later phases.
|
*/

/*
 * Health & readiness.
 *
 * Rate limited separately from the rest of the API: a monitoring system polls
 * these on a fixed interval and must not consume the public request budget,
 * nor be locked out by unrelated traffic bursts.
 */
Route::prefix('health')
    ->name('health.')
    ->middleware('throttle:health')
    ->group(function (): void {
        Route::get('/', [HealthController::class, 'index'])->name('index');
        Route::get('/ready', [HealthController::class, 'ready'])->name('ready');
    });

/*
 * Public storefront configuration.
 *
 * Serves the admin-managed branding payload the Next.js app renders — company
 * name, logo, favicon, theme colours, contact details, social links.
 */
Route::middleware('throttle:public')->group(function (): void {
    Route::get('/settings/public', [SettingsController::class, 'index'])
        ->name('settings.public');
});

/*
|--------------------------------------------------------------------------
| Customer Authentication
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->name('auth.')->group(function (): void {

    /*
     * Unauthenticated credential endpoints.
     *
     * `throttle:auth` is far stricter than the public limiter — these are the
     * endpoints a credential-stuffing attack targets, and they are keyed on
     * email+IP so one account cannot be brute-forced from many addresses, nor
     * one address lock out everyone behind a shared NAT.
     */
    Route::middleware('throttle:auth')->group(function (): void {
        Route::post('/register', [CustomerAuthController::class, 'register'])->name('register');
        Route::post('/login', [CustomerAuthController::class, 'login'])->name('login');
    });

    // Separate, even stricter budget: each request here sends an email, so
    // the limit protects mailboxes and mail reputation as much as the app.
    Route::middleware('throttle:password-reset')->group(function (): void {
        Route::post('/forgot-password', [PasswordResetController::class, 'sendCustomerResetLink'])
            ->name('forgot-password');
        Route::post('/reset-password', [PasswordResetController::class, 'resetCustomerPassword'])
            ->name('reset-password');
    });

    /*
     * Email verification.
     *
     * Opened from a mail client, so there is no bearer token — the `signed`
     * middleware authenticates the request by verifying the URL signature.
     */
    Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:verification'])
        ->name('verify-email');

    /*
     * Authenticated customer endpoints.
     *
     * `auth:sanctum` alone would also accept an *admin* token, because Sanctum
     * resolves any valid token. `abilities:customer:access` is what confines
     * these routes to customer-issued tokens.
     */
    Route::middleware(['auth:sanctum', 'throttle:authenticated'])->group(function (): void {

        // Available while unverified so a blocked user can escape the state.
        Route::post('/email/resend', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:verification')
            ->name('email.resend');
        Route::get('/email/status', [EmailVerificationController::class, 'status'])->name('email.status');

        Route::get('/me', [CustomerAuthController::class, 'me'])->name('me');
        Route::post('/logout', [CustomerAuthController::class, 'logout'])->name('logout');
        Route::post('/logout-all', [CustomerAuthController::class, 'logoutAll'])->name('logout-all');

        // Require a verified address: these mutate the account itself.
        Route::middleware('verified')->group(function (): void {
            Route::patch('/profile', [CustomerAuthController::class, 'updateProfile'])->name('profile.update');
            Route::post('/change-password', [CustomerAuthController::class, 'changePassword'])
                ->name('change-password');
        });
    });
});

/*
|--------------------------------------------------------------------------
| Admin Authentication & Management
|--------------------------------------------------------------------------
*/

Route::prefix('admin')->name('admin.')->group(function (): void {

    Route::prefix('auth')->name('auth.')->group(function (): void {

        // No registration endpoint by design: staff accounts are created only
        // by an existing administrator holding `manage_admins`.
        Route::post('/login', [AdminAuthController::class, 'login'])
            ->middleware('throttle:admin-auth')
            ->name('login');

        Route::middleware('throttle:password-reset')->group(function (): void {
            Route::post('/forgot-password', [PasswordResetController::class, 'sendAdminResetLink'])
                ->name('forgot-password');
            Route::post('/reset-password', [PasswordResetController::class, 'resetAdminPassword'])
                ->name('reset-password');
        });

        /*
         * Authenticated staff endpoints.
         *
         * `admin.active` revalidates the account on every request, so
         * deactivating an admin takes effect immediately rather than when
         * their token expires.
         *
         * These sit outside the `admin.password` gate: an admin under forced
         * rotation must still be able to read their profile, change their
         * password, and log out.
         */
        Route::middleware(['auth:admin-api', 'admin.active', 'throttle:authenticated'])
            ->group(function (): void {
                Route::get('/me', [AdminAuthController::class, 'me'])->name('me');
                Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');
                Route::post('/logout-all', [AdminAuthController::class, 'logoutAll'])->name('logout-all');
                Route::post('/change-password', [AdminAuthController::class, 'changePassword'])
                    ->name('change-password');
            });
    });

    /*
     * Staff-only management endpoints.
     *
     * Every route below requires: a valid admin token, an active account, a
     * current password, and an explicit permission. Policies add the
     * per-record rank checks that middleware cannot express.
     */
    Route::middleware(['auth:admin-api', 'admin.active', 'admin.password', 'throttle:authenticated'])
        ->group(function (): void {

            Route::prefix('admins')->name('admins.')->group(function (): void {
                Route::get('/', [AdminManagementController::class, 'index'])
                    ->middleware('permission:view_admins,manage_admins')
                    ->name('index');

                Route::post('/', [AdminManagementController::class, 'store'])
                    ->middleware('permission:manage_admins')
                    ->name('store');

                Route::get('/{admin}', [AdminManagementController::class, 'show'])
                    ->middleware('permission:view_admins,manage_admins')
                    ->name('show');

                Route::patch('/{admin}', [AdminManagementController::class, 'update'])
                    ->middleware('permission:manage_admins')
                    ->name('update');

                Route::delete('/{admin}', [AdminManagementController::class, 'destroy'])
                    ->middleware('permission:manage_admins')
                    ->name('destroy');

                Route::patch('/{admin}/status', [AdminManagementController::class, 'setStatus'])
                    ->middleware('permission:manage_admins')
                    ->name('status');

                // Role and permission assignment needs `manage_roles`, which
                // is strictly more privileged than `manage_admins`: it is the
                // capability that can escalate privileges.
                Route::put('/{admin}/roles', [AdminManagementController::class, 'assignRoles'])
                    ->middleware('permission:manage_roles')
                    ->name('roles');

                Route::put('/{admin}/permissions', [AdminManagementController::class, 'assignPermissions'])
                    ->middleware('permission:manage_roles')
                    ->name('permissions');
            });

            /*
             * Dynamic store settings — branding, theme, SEO, business rules.
             *
             * Read paths accept `view_settings` as well, so a read-only staff
             * role can inspect configuration without being able to restyle the
             * storefront. Every write requires `manage_settings`.
             *
             * Unlike /settings/public, this surface exposes private groups
             * (mail, payment), which is why it is permission-gated rather than
             * merely authenticated.
             */
            Route::prefix('settings')->name('settings.')->group(function (): void {
                Route::get('/', [SettingsManagementController::class, 'index'])
                    ->middleware('permission:view_settings,manage_settings')
                    ->name('index');

                Route::get('/groups', [SettingsManagementController::class, 'groups'])
                    ->middleware('permission:view_settings,manage_settings')
                    ->name('groups');

                Route::put('/', [SettingsManagementController::class, 'update'])
                    ->middleware('permission:manage_settings')
                    ->name('update');

                Route::post('/cache/flush', [SettingsManagementController::class, 'flushCache'])
                    ->middleware('permission:manage_settings')
                    ->name('cache.flush');

                /*
                 * Brand asset upload and removal.
                 *
                 * The key is constrained to the dot-namespaced format so the
                 * route cannot swallow a path segment and match something
                 * unintended.
                 */
                Route::post('/media/{key}', [SettingsManagementController::class, 'uploadMedia'])
                    ->where('key', '[a-z0-9_]+\.[a-z0-9_]+')
                    ->middleware('permission:manage_settings')
                    ->name('media.upload');

                Route::delete('/media/{key}', [SettingsManagementController::class, 'destroyMedia'])
                    ->where('key', '[a-z0-9_]+\.[a-z0-9_]+')
                    ->middleware('permission:manage_settings')
                    ->name('media.destroy');
            });

            Route::get('/roles', [RoleController::class, 'index'])
                ->middleware('permission:manage_roles,view_admins')
                ->name('roles.index');

            Route::get('/roles/{role}', [RoleController::class, 'show'])
                ->middleware('permission:manage_roles,view_admins')
                ->name('roles.show');

            Route::get('/permissions', [RoleController::class, 'permissions'])
                ->middleware('permission:manage_roles,view_admins')
                ->name('permissions.index');
        });
});

/*
 * Fallback for unmatched /api/v1/* paths.
 *
 * Without this, an unknown API path falls through to Laravel's generic 404
 * and escapes the API error envelope.
 */
Route::fallback(function (): never {
    abort(404, 'The requested endpoint does not exist in API v1.');
})->name('fallback');
