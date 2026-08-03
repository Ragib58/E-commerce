<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AdminAuthController;
use App\Http\Controllers\Api\V1\Admin\AdminManagementController;
use App\Http\Controllers\Api\V1\Admin\AttributeController;
use App\Http\Controllers\Api\V1\Admin\BrandController;
use App\Http\Controllers\Api\V1\Admin\CategoryController;
use App\Http\Controllers\Api\V1\Admin\InventoryController;
use App\Http\Controllers\Api\V1\Admin\ProductController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SettingsManagementController;
use App\Http\Controllers\Api\V1\Admin\VariantController;
use App\Http\Controllers\Api\V1\Auth\CustomerAuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\CatalogController;
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
| Public Catalog
|--------------------------------------------------------------------------
|
| Unauthenticated, read-only. Every query is constrained to published records
| inside CatalogService, so no parameter here can surface a draft product.
|
| Slugs rather than ids throughout: they are the storefront's URLs, and integer
| ids would leak catalog size and invite enumeration.
|
*/

Route::middleware('throttle:public')->group(function (): void {

    /*
     * Static segments are declared before the wildcard route.
     *
     * Laravel matches in declaration order, so `/products/{slug}` registered
     * first would swallow every path below it — a product slugged "filters"
     * is not needed for that to break; the route simply never gets a chance.
     */
    Route::get('/catalog/filters', [CatalogController::class, 'filters'])->name('catalog.filters');

    Route::get('/catalog/rails/{rail}', [CatalogController::class, 'rail'])
        ->where('rail', 'featured|new_arrivals|best_sellers')
        ->name('catalog.rail');

    Route::get('/products', [CatalogController::class, 'products'])->name('products.index');
    Route::get('/products/{slug}', [CatalogController::class, 'product'])
        ->where('slug', '[a-z0-9-]+')
        ->name('products.show');

    Route::get('/categories', [CatalogController::class, 'categories'])->name('categories.index');
    Route::get('/categories/{slug}', [CatalogController::class, 'category'])
        ->where('slug', '[a-z0-9-]+')
        ->name('categories.show');

    Route::get('/brands', [CatalogController::class, 'brands'])->name('brands.index');
    Route::get('/brands/{slug}', [CatalogController::class, 'brand'])
        ->where('slug', '[a-z0-9-]+')
        ->name('brands.show');
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

            /*
             * Catalog administration.
             *
             * Permissions are split four ways for products (view / create /
             * update / delete) because the roles genuinely differ: a
             * merchandiser edits copy and pricing all day, while deleting a
             * product withdraws something with order history behind it.
             *
             * Categories and brands use a single `manage_*` write permission —
             * restructuring a taxonomy is one skill, not three.
             */

            Route::prefix('categories')->name('categories.')->group(function (): void {
                // Declared before /{category} so the literal segment is not
                // captured as a slug.
                Route::put('/reorder', [CategoryController::class, 'reorder'])
                    ->middleware('permission:manage_categories')
                    ->name('reorder');

                Route::get('/', [CategoryController::class, 'index'])
                    ->middleware('permission:view_categories,manage_categories,view_products')
                    ->name('index');

                Route::post('/', [CategoryController::class, 'store'])
                    ->middleware('permission:manage_categories')
                    ->name('store');

                Route::get('/{category}', [CategoryController::class, 'show'])
                    ->middleware('permission:view_categories,manage_categories,view_products')
                    ->name('show');

                Route::patch('/{category}', [CategoryController::class, 'update'])
                    ->middleware('permission:manage_categories')
                    ->name('update');

                Route::delete('/{category}', [CategoryController::class, 'destroy'])
                    ->middleware('permission:manage_categories')
                    ->name('destroy');

                Route::patch('/{category}/status', [CategoryController::class, 'setStatus'])
                    ->middleware('permission:manage_categories')
                    ->name('status');
            });

            Route::prefix('brands')->name('brands.')->group(function (): void {
                Route::get('/', [BrandController::class, 'index'])
                    ->middleware('permission:view_brands,manage_brands,view_products')
                    ->name('index');

                Route::post('/', [BrandController::class, 'store'])
                    ->middleware('permission:manage_brands')
                    ->name('store');

                Route::get('/{brand}', [BrandController::class, 'show'])
                    ->middleware('permission:view_brands,manage_brands,view_products')
                    ->name('show');

                Route::patch('/{brand}', [BrandController::class, 'update'])
                    ->middleware('permission:manage_brands')
                    ->name('update');

                Route::delete('/{brand}', [BrandController::class, 'destroy'])
                    ->middleware('permission:manage_brands')
                    ->name('destroy');

                Route::patch('/{brand}/status', [BrandController::class, 'setStatus'])
                    ->middleware('permission:manage_brands')
                    ->name('status');
            });

            Route::prefix('products')->name('products.')->group(function (): void {
                Route::post('/bulk', [ProductController::class, 'bulk'])
                    ->middleware('permission:update_products')
                    ->name('bulk');

                Route::get('/', [ProductController::class, 'index'])
                    ->middleware('permission:view_products')
                    ->name('index');

                Route::post('/', [ProductController::class, 'store'])
                    ->middleware('permission:create_products')
                    ->name('store');

                /*
                 * Restore takes `trashedProduct`, not `product`, so the
                 * registered model binding does not intercept it — that
                 * binding resolves live rows only, and would 404 on the very
                 * records this route exists to recover.
                 */
                Route::post('/{trashedProduct}/restore', [ProductController::class, 'restore'])
                    ->middleware('permission:delete_products')
                    ->name('restore');

                Route::get('/{product}', [ProductController::class, 'show'])
                    ->middleware('permission:view_products')
                    ->name('show');

                Route::patch('/{product}', [ProductController::class, 'update'])
                    ->middleware('permission:update_products')
                    ->name('update');

                Route::delete('/{product}', [ProductController::class, 'destroy'])
                    ->middleware('permission:delete_products')
                    ->name('destroy');

                Route::patch('/{product}/status', [ProductController::class, 'setStatus'])
                    ->middleware('permission:update_products')
                    ->name('status');

                /*
                 * Gallery management.
                 */
                Route::post('/{product}/media', [ProductController::class, 'uploadMedia'])
                    ->middleware('permission:update_products')
                    ->name('media.upload');

                Route::put('/{product}/media/reorder', [ProductController::class, 'reorderMedia'])
                    ->middleware('permission:update_products')
                    ->name('media.reorder');

                Route::patch('/{product}/media/{media}/thumbnail', [ProductController::class, 'setThumbnail'])
                    ->middleware('permission:update_products')
                    ->name('media.thumbnail');

                Route::delete('/{product}/media/{media}', [ProductController::class, 'destroyMedia'])
                    ->middleware('permission:update_products')
                    ->name('media.destroy');

                /*
                 * Variants, nested under their product.
                 */
                Route::get('/{product}/variants', [VariantController::class, 'index'])
                    ->middleware('permission:view_products')
                    ->name('variants.index');

                Route::post('/{product}/variants', [VariantController::class, 'store'])
                    ->middleware('permission:update_products')
                    ->name('variants.store');

                Route::post('/{product}/variants/generate', [VariantController::class, 'generate'])
                    ->middleware('permission:update_products')
                    ->name('variants.generate');

                /*
                 * Stock. Gated on `update_products` rather than a catalog
                 * authoring permission, so a warehouse account can record
                 * counts without being able to create or delete products.
                 */
                Route::post('/{product}/stock', [InventoryController::class, 'adjust'])
                    ->middleware('permission:update_products')
                    ->name('stock.adjust');

                Route::get('/{product}/stock/history', [InventoryController::class, 'history'])
                    ->middleware('permission:view_products')
                    ->name('stock.history');
            });

            /*
             * Variant mutation by uuid, outside the product prefix: the admin
             * UI edits a variant from a row it already holds, without needing
             * to thread the parent product through the URL.
             */
            Route::patch('/variants/{variant}', [VariantController::class, 'update'])
                ->middleware('permission:update_products')
                ->name('variants.update');

            Route::delete('/variants/{variant}', [VariantController::class, 'destroy'])
                ->middleware('permission:update_products')
                ->name('variants.destroy');

            /*
             * Inventory reporting across the whole catalog.
             */
            Route::prefix('inventory')->name('inventory.')->group(function (): void {
                Route::get('/movements', [InventoryController::class, 'movements'])
                    ->middleware('permission:view_products')
                    ->name('movements');

                Route::get('/alerts', [InventoryController::class, 'alerts'])
                    ->middleware('permission:view_products')
                    ->name('alerts');

                Route::get('/summary', [InventoryController::class, 'summary'])
                    ->middleware('permission:view_products')
                    ->name('summary');
            });

            /*
             * Variant attributes — the rows that make Size and Colour data
             * rather than schema.
             */
            Route::prefix('attributes')->name('attributes.')->group(function (): void {
                Route::get('/', [AttributeController::class, 'index'])
                    ->middleware('permission:view_products')
                    ->name('index');

                Route::post('/', [AttributeController::class, 'store'])
                    ->middleware('permission:create_products')
                    ->name('store');

                Route::patch('/{attribute}', [AttributeController::class, 'update'])
                    ->middleware('permission:update_products')
                    ->name('update');

                Route::delete('/{attribute}', [AttributeController::class, 'destroy'])
                    ->middleware('permission:delete_products')
                    ->name('destroy');

                Route::post('/{attribute}/values', [AttributeController::class, 'storeValue'])
                    ->middleware('permission:update_products')
                    ->name('values.store');

                Route::delete('/{attribute}/values/{value}', [AttributeController::class, 'destroyValue'])
                    ->middleware('permission:update_products')
                    ->name('values.destroy');
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
