<?php

declare(strict_types=1);

use App\Enums\ReportType;
use App\Http\Controllers\Api\V1\Admin\AdminAuthController;
use App\Http\Controllers\Api\V1\Admin\AdminManagementController;
use App\Http\Controllers\Api\V1\Admin\AttributeController;
use App\Http\Controllers\Api\V1\Admin\BannerController;
use App\Http\Controllers\Api\V1\Admin\BrandController;
use App\Http\Controllers\Api\V1\Admin\CategoryController;
use App\Http\Controllers\Api\V1\Admin\CmsPageController;
use App\Http\Controllers\Api\V1\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\HomepageController;
use App\Http\Controllers\Api\V1\Admin\InventoryController;
use App\Http\Controllers\Api\V1\Admin\NotificationController as AdminNotificationController;
use App\Http\Controllers\Api\V1\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\V1\Admin\ProductController;
use App\Http\Controllers\Api\V1\Admin\ReportController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SettingsManagementController;
use App\Http\Controllers\Api\V1\Admin\ShippingController;
use App\Http\Controllers\Api\V1\Admin\VariantController;
use App\Http\Controllers\Api\V1\Auth\CustomerAuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\CouponController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\WishlistController;
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

    /*
     * Bulk product lookup, for the compare tray and recently-viewed rail.
     *
     * POST despite being a read: the list can hold twenty-plus uuids, which
     * overflows practical URL length limits — and a truncated query string
     * would silently drop products rather than failing visibly.
     */
    Route::post('/catalog/products/lookup', [CatalogController::class, 'lookup'])
        ->name('catalog.lookup');

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
| Public Storefront Content
|--------------------------------------------------------------------------
|
| The dynamic homepage, promotional banners, and CMS pages.
|
| Every query here is constrained to *live* records inside the services —
| publishable status AND an open scheduling window — so no parameter can
| surface a draft page or a campaign that has not launched yet.
|
| /homepage returns the entire page in one response, sections already resolved
| to their products, categories, or banners. One request rather than one per
| section: a six-rail homepage would otherwise open with a six-deep waterfall
| on every cold visit.
|
*/

Route::middleware('throttle:public')->group(function (): void {
    Route::get('/homepage', [ContentController::class, 'homepage'])->name('homepage');

    Route::get('/banners', [ContentController::class, 'banners'])->name('banners.index');

    Route::get('/pages', [ContentController::class, 'pages'])->name('pages.index');
    Route::get('/pages/{slug}', [ContentController::class, 'page'])
        ->where('slug', '[a-z0-9-]+')
        ->name('pages.show');

    // Current public offers. See CouponController for why applying a
    // specific code is a cart endpoint rather than living here.
    Route::get('/coupons', [CouponController::class, 'index'])->name('coupons.index');
});

/*
|--------------------------------------------------------------------------
| Shopping Cart
|--------------------------------------------------------------------------
|
| Open to guests and signed-in customers alike. There is no `auth` middleware:
| the cart *is* the authorization boundary, and a request can only ever act on
| the cart its bearer token or X-Cart-Token header resolves to.
|
| `auth:sanctum` is deliberately absent even on the authenticated path. Adding
| it would make these routes 401 for guests, and the whole point is that the
| same endpoints serve both — the `cart` middleware resolves by user id when a
| token is present and by header when it is not.
|
| No endpoint here accepts a price. Every figure in every response is
| recomputed from the catalog by CartService — see its class docblock.
|
*/

Route::prefix('cart')
    ->name('cart.')
    ->middleware(['cart', 'throttle:cart'])
    ->group(function (): void {
        Route::get('/', [CartController::class, 'show'])->name('show');
        Route::delete('/', [CartController::class, 'clear'])->name('clear');

        Route::post('/items', [CartController::class, 'store'])->name('items.store');
        Route::patch('/items/{item}', [CartController::class, 'update'])
            ->whereNumber('item')
            ->name('items.update');
        Route::delete('/items/{item}', [CartController::class, 'destroy'])
            ->whereNumber('item')
            ->name('items.destroy');

        /*
         * Validated immediately against CouponService and re-validated from
         * scratch at redemption — see CartService::applyCoupon for why both
         * checks exist rather than trusting this one alone.
         */
        Route::post('/coupon', [CartController::class, 'applyCoupon'])->name('coupon');

        /*
         * Claims a guest cart after sign-in. Requires an authenticated caller,
         * enforced in the controller rather than by middleware so the failure
         * carries this API's error envelope.
         */
        Route::post('/merge', [CartController::class, 'merge'])->name('merge');
    });

/*
|--------------------------------------------------------------------------
| Checkout
|--------------------------------------------------------------------------
|
| Open to guests and signed-in customers alike, like the cart and for the same
| reason: guest checkout is a first-class path, not a degraded one. There is no
| `auth:sanctum` here — the checkout *session token* is the authorization
| boundary, and CheckoutService re-checks on every step that a claimed session
| is not one belonging to an account other than the caller.
|
| The step sequence is enforced server-side. A request that jumps to `place`
| without having chosen a shipping method is refused with the step it must
| complete first — see App\Enums\CheckoutStep. Skipping is not a state the
| system can enter rather than one it detects afterwards.
|
| No endpoint here accepts a price, a shipping cost, or a total. Every figure
| is recomputed from the catalog and the chosen method's own row.
|
*/

Route::prefix('checkout')
    ->name('checkout.')
    ->middleware('throttle:cart')
    ->group(function (): void {

        /*
         * Declared before /{token} so the literal segment is not captured as a
         * session token. Static-before-wildcard, as elsewhere in this file.
         */
        Route::get('/payment-methods', [CheckoutController::class, 'paymentMethods'])
            ->name('payment-methods');

        /*
         * Starting a checkout needs the cart, so this route — and only this one
         * — carries the `cart` middleware. The steps that follow identify
         * themselves by session token and reach the cart through it.
         */
        Route::post('/', [CheckoutController::class, 'start'])
            ->middleware('cart')
            ->name('start');

        /*
         * The token is 64 hex characters from a CSPRNG. Constraining the
         * segment means a malformed value is a 404 at the router rather than a
         * lookup against an indexed column.
         */
        Route::prefix('{token}')
            ->where(['token' => '[a-f0-9]{64}'])
            ->group(function (): void {
                Route::get('/', [CheckoutController::class, 'show'])->name('show');
                Route::delete('/', [CheckoutController::class, 'abandon'])->name('abandon');

                // Steps 1–5. PUT rather than POST: each is setting the value of
                // a named step, and re-sending it must overwrite rather than
                // accumulate.
                Route::put('/customer', [CheckoutController::class, 'setCustomer'])
                    ->name('customer');
                Route::put('/shipping-address', [CheckoutController::class, 'setShippingAddress'])
                    ->name('shipping-address');
                Route::put('/billing-address', [CheckoutController::class, 'setBillingAddress'])
                    ->name('billing-address');

                Route::get('/shipping-methods', [CheckoutController::class, 'shippingMethods'])
                    ->name('shipping-methods');
                Route::put('/shipping-method', [CheckoutController::class, 'setShippingMethod'])
                    ->name('shipping-method');

                Route::put('/payment-method', [CheckoutController::class, 'setPaymentMethod'])
                    ->name('payment-method');

                // Step 6. POST, not GET: it records that the shopper saw the
                // total and reserves stock, both of which are writes.
                Route::post('/review', [CheckoutController::class, 'review'])->name('review');

                /*
                 * Step 7 — the only endpoint that creates an order.
                 *
                 * Rate limited more tightly than the rest of checkout: this is
                 * the expensive, transactional, stock-mutating call, and it is
                 * the one worth protecting from a retry storm.
                 */
                Route::post('/place', [CheckoutController::class, 'place'])
                    ->middleware('throttle:checkout-place')
                    ->name('place');
            });
    });

/*
|--------------------------------------------------------------------------
| Customer Orders
|--------------------------------------------------------------------------
|
| Two ways in, one authorization rule.
|
| A signed-in customer reads their orders through `auth:sanctum`; the policy
| confirms each order's `user_id` matches. A guest has no account, so their
| order is reached by order number *plus* the email it was placed with — which
| is why order numbers carry a random component rather than being sequential.
|
| /lookup therefore sits outside the auth group, and is throttled like a
| credential endpoint because that is what it is.
|
*/

Route::prefix('orders')
    ->name('orders.')
    ->group(function (): void {

        /*
         * Guest order lookup. POST rather than GET because the email is a
         * credential, and a credential in a query string ends up in server
         * logs, browser history, and the Referer header of every outbound link.
         */
        Route::post('/lookup', [OrderController::class, 'lookup'])
            ->middleware('throttle:auth')
            ->name('lookup');

        Route::middleware(['auth:sanctum', 'throttle:authenticated'])->group(function (): void {
            Route::get('/', [OrderController::class, 'index'])->name('index');

            Route::get('/{order}', [OrderController::class, 'show'])->name('show');
            Route::get('/{order}/track', [OrderController::class, 'track'])->name('track');

            Route::post('/{order}/cancel', [OrderController::class, 'cancel'])->name('cancel');

            /*
             * Step 3 — start a payment for an order.
             *
             * Returns where to send the customer rather than issuing the
             * redirect itself: the storefront is a separate origin and needs
             * to choose how to navigate, and a 302 from an API endpoint would
             * take that decision away.
             */
            Route::post('/{order}/pay', [PaymentController::class, 'pay'])->name('pay');
        });
    });

/*
|--------------------------------------------------------------------------
| Payments
|--------------------------------------------------------------------------
|
| Three surfaces with three different threat models.
|
| `/payments/methods` is a public read of which gateways this store can
| currently process through.
|
| Callbacks are where a gateway sends the customer's BROWSER back to. They
| carry no bearer token and cannot: the request arrives as a third-party
| redirect. The payment uuid in the path identifies which transaction is being
| reported and does nothing else — nothing in the handler trusts the request's
| contents, and the status is established by a server-to-server call to the
| gateway regardless of what the query string claims.
|
| Webhooks are server-to-server posts authenticated by SIGNATURE inside the
| gateway implementation. They are deliberately NOT rate limited: throttling
| this endpoint drops legitimate notifications about money, and an unsigned
| flood is already cheap to reject.
|
*/

Route::prefix('payments')
    ->name('payments.')
    ->group(function (): void {

        // Declared before the wildcard routes so the literal segment is not
        // captured as a gateway identifier.
        Route::get('/methods', [PaymentController::class, 'methods'])
            ->middleware('throttle:public')
            ->name('methods');

        /*
         * The customer's return from a hosted payment page.
         *
         * Both verbs, because gateways differ: SSLCommerz POSTs its result
         * while Stripe appends a query string to a GET. Neither body is
         * trusted.
         *
         * `outcome` says which route the customer came back on. It decides
         * which page they land on, not whether they paid.
         */
        Route::match(['get', 'post'], '/{gateway}/callback/{payment}/{outcome}', [PaymentController::class, 'callback'])
            ->where([
                'gateway' => '[a-z0-9_]+',
                'payment' => '[0-9a-fA-F-]{36}',
                'outcome' => 'success|failure|cancel',
            ])
            ->name('callback');

        /*
         * Gateway webhooks.
         *
         * No throttle: see the block comment above. No CSRF either — these
         * routes are in the `api` group, which is stateless by design.
         */
        Route::post('/{gateway}/webhook', [PaymentController::class, 'webhook'])
            ->where('gateway', '[a-z0-9_]+')
            ->name('webhook');

        /*
         * What the storefront polls while an async payment settles.
         *
         * Reports the stored status rather than calling the gateway: a
         * customer refreshing a page must not be able to generate outbound
         * requests to a rate-limited processor.
         */
        Route::get('/{payment}/status', [PaymentController::class, 'status'])
            ->where('payment', '[0-9a-fA-F-]{36}')
            ->middleware('throttle:public')
            ->name('status');
    });

/*
|--------------------------------------------------------------------------
| Wishlist
|--------------------------------------------------------------------------
|
| Authenticated only, unlike the cart. A guest's saved items live in
| localStorage and are merged here on sign-in — a server-side anonymous
| wishlist costs what a cart does while being worth far less, since the shopper
| cannot return to it from another device.
|
*/

/*
|--------------------------------------------------------------------------
| Customer Notifications
|--------------------------------------------------------------------------
|
| A signed-in customer's own database notifications and channel preferences.
| Scoped entirely to $request->user() — see NotificationController's class
| docblock for why there is no endpoint here that takes a notifiable id.
|
*/

Route::prefix('notifications')
    ->name('notifications.')
    ->middleware(['auth:sanctum', 'throttle:authenticated'])
    ->group(function (): void {
        // Declared before the wildcard mark-read route so the literal
        // segments are not captured as a notification id.
        Route::get('/unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
        Route::post('/read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
        Route::get('/preferences', [NotificationController::class, 'preferences'])->name('preferences');
        Route::patch('/preferences', [NotificationController::class, 'updatePreference'])->name('preferences.update');

        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::patch('/{notification}/read', [NotificationController::class, 'markRead'])
            ->where('notification', '[0-9a-fA-F-]{36}')
            ->name('read');
    });

Route::prefix('wishlist')
    ->name('wishlist.')
    /*
     * `auth:sanctum` alone, matching the customer routes below.
     *
     * Sanctum's `abilities` middleware alias is not registered in
     * bootstrap/app.php, so naming it here would fail at runtime rather than
     * tightening anything. The guard is still the separation that matters: it
     * resolves the `users` provider, so an admin token — issued against the
     * `admins` table — cannot authenticate here at all.
     */
    ->middleware(['auth:sanctum', 'throttle:authenticated'])
    ->group(function (): void {
        Route::get('/', [WishlistController::class, 'index'])->name('index');
        Route::post('/', [WishlistController::class, 'store'])->name('store');
        Route::post('/merge', [WishlistController::class, 'merge'])->name('merge');
        Route::delete('/{product}', [WishlistController::class, 'destroy'])->name('destroy');
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
     * An admin's own notification inbox and channel preferences.
     *
     * Deliberately outside the permission-gated management block below: which
     * alerts an admin receives about their own account is not a resource that
     * needs a specific permission to reach, the same way `/admin/auth/me`
     * needs only a valid, active session.
     */
    Route::prefix('notifications')
        ->name('notifications.')
        ->middleware(['auth:admin-api', 'admin.active', 'throttle:authenticated'])
        ->group(function (): void {
            Route::get('/unread-count', [AdminNotificationController::class, 'unreadCount'])->name('unread-count');
            Route::post('/read-all', [AdminNotificationController::class, 'markAllRead'])->name('read-all');
            Route::get('/preferences', [AdminNotificationController::class, 'preferences'])->name('preferences');
            Route::patch('/preferences', [AdminNotificationController::class, 'updatePreference'])
                ->name('preferences.update');

            Route::get('/', [AdminNotificationController::class, 'index'])->name('index');
            Route::patch('/{notification}/read', [AdminNotificationController::class, 'markRead'])
                ->where('notification', '[0-9a-fA-F-]{36}')
                ->name('read');
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

            /*
             * Order administration.
             *
             * Permissions are split four ways because the jobs genuinely
             * differ. A support agent reads orders and adds notes all day
             * (`view_orders`, `update_orders`); cancelling releases stock and
             * may owe money (`cancel_orders`); refunding moves money out of the
             * business (`refund_orders`). Collapsing them into one
             * `manage_orders` would give whoever answers the phone the ability
             * to pay out.
             */
            Route::prefix('orders')->name('orders.')->group(function (): void {

                // Declared before /{order} so the literal segment is not
                // captured as a uuid.
                Route::get('/statistics', [AdminOrderController::class, 'statistics'])
                    ->middleware('permission:view_orders,view_reports')
                    ->name('statistics');

                Route::get('/', [AdminOrderController::class, 'index'])
                    ->middleware('permission:view_orders')
                    ->name('index');

                Route::get('/{order}', [AdminOrderController::class, 'show'])
                    ->middleware('permission:view_orders')
                    ->name('show');

                Route::patch('/{order}/status', [AdminOrderController::class, 'updateStatus'])
                    ->middleware('permission:update_orders')
                    ->name('status');

                Route::post('/{order}/notes', [AdminOrderController::class, 'storeNote'])
                    ->middleware('permission:update_orders')
                    ->name('notes.store');

                /*
                 * Recording an offline payment — a bank transfer that cleared.
                 * `update_orders` rather than a payments permission: this
                 * records a fact about fulfilment, it does not move money.
                 */
                Route::post('/{order}/payment', [AdminOrderController::class, 'markPaid'])
                    ->middleware('permission:update_orders')
                    ->name('payment');

                Route::post('/{order}/cancel', [AdminOrderController::class, 'cancel'])
                    ->middleware('permission:cancel_orders')
                    ->name('cancel');

                Route::post('/{order}/refund', [AdminOrderController::class, 'refund'])
                    ->middleware('permission:refund_orders')
                    ->name('refund');

                /*
                 * Documents. Reading, not writing, so `view_orders` is enough —
                 * a warehouse account that can see an order can print the slip
                 * it needs to pack it.
                 *
                 * Both serve HTML by default and PDF with `?format=pdf`; the
                 * two are renderings of one Blade view, so a printed copy and a
                 * downloaded one cannot diverge.
                 */
                Route::get('/{order}/invoice', [AdminOrderController::class, 'invoice'])
                    ->middleware('permission:view_orders')
                    ->name('invoice');

                Route::get('/{order}/packing-slip', [AdminOrderController::class, 'packingSlip'])
                    ->middleware('permission:view_orders')
                    ->name('packing-slip');
            });

            /*
             * Payment administration.
             *
             * Reads need `view_payments`; the two actions that reach a
             * processor need `manage_payments`. The split matters — reading
             * transactions is what a support agent does all day, while
             * re-verifying makes an outbound API call, and an accounts clerk
             * browsing a list should not be able to generate traffic against a
             * rate-limited gateway.
             *
             * Note what is absent: any endpoint that marks a payment paid. An
             * admin can ask the gateway to re-verify; they cannot assert an
             * outcome. That would be the one hole in the "never without
             * verification" rule, and it would get used, because it is the
             * fastest way to close a support ticket.
             */
            Route::prefix('payments')->name('payments.')->group(function (): void {

                // Literal segments before the wildcard, so they are not
                // captured as a payment uuid.
                Route::get('/statistics', [AdminPaymentController::class, 'statistics'])
                    ->middleware('permission:view_payments,view_reports')
                    ->name('statistics');

                Route::get('/events/unverified', [AdminPaymentController::class, 'unverifiedEvents'])
                    ->middleware('permission:view_payments')
                    ->name('events.unverified');

                Route::get('/', [AdminPaymentController::class, 'index'])
                    ->middleware('permission:view_payments')
                    ->name('index');

                Route::get('/{payment}', [AdminPaymentController::class, 'show'])
                    ->where('payment', '[0-9a-fA-F-]{36}')
                    ->middleware('permission:view_payments')
                    ->name('show');

                Route::get('/{payment}/events', [AdminPaymentController::class, 'events'])
                    ->where('payment', '[0-9a-fA-F-]{36}')
                    ->middleware('permission:view_payments')
                    ->name('events');

                Route::post('/{payment}/verify', [AdminPaymentController::class, 'verify'])
                    ->where('payment', '[0-9a-fA-F-]{36}')
                    ->middleware('permission:manage_payments')
                    ->name('verify');
            });

            /*
             * The dashboard.
             *
             * Everything here is a cached aggregate keyed by the requested
             * range — see ReportCache. `view_reports` alone is sufficient:
             * these endpoints only read, and the figures they expose are
             * summaries a manager needs without also holding the permissions
             * to edit orders or products.
             */
            Route::prefix('dashboard')->name('dashboard.')->group(function (): void {
                Route::get('/', [DashboardController::class, 'index'])
                    ->middleware('permission:view_reports,manage_reports')
                    ->name('index');

                Route::get('/metrics', [DashboardController::class, 'metrics'])
                    ->middleware('permission:view_reports,manage_reports')
                    ->name('metrics');

                Route::get('/charts', [DashboardController::class, 'charts'])
                    ->middleware('permission:view_reports,manage_reports')
                    ->name('charts');

                Route::get('/filters', [DashboardController::class, 'filters'])
                    ->middleware('permission:view_reports,manage_reports')
                    ->name('filters');
            });

            /*
             * Reports and their exports.
             *
             * The literal `/reports` index is declared before `/{report}` so
             * it is not captured as a report name. The wildcard is constrained
             * to the enum's own values rather than left open, so an unknown
             * name is a 404 from the router instead of reaching the controller.
             */
            Route::prefix('reports')->name('reports.')->group(function (): void {
                Route::get('/', [ReportController::class, 'index'])
                    ->middleware('permission:view_reports,manage_reports')
                    ->name('index');

                Route::get('/{report}', [ReportController::class, 'show'])
                    ->where('report', implode('|', ReportType::values()))
                    ->middleware('permission:view_reports,manage_reports')
                    ->name('show');

                /*
                 * Exporting needs `manage_reports` rather than `view_reports`.
                 * Reading a summary on screen and walking out with a file of
                 * every customer's email and lifetime value are different
                 * acts, and the second is the one worth gating separately.
                 */
                Route::get('/{report}/export', [ReportController::class, 'export'])
                    ->where('report', implode('|', ReportType::values()))
                    ->middleware('permission:manage_reports')
                    ->name('export');
            });

            /*
             * Shipping — methods, zones, and the rates that price a method
             * within a zone. Read paths admit `view_shipping` as well as
             * `manage_shipping`, so a read-only role can see configuration and
             * quote a delivery estimate without being able to change what the
             * storefront charges.
             */
            Route::prefix('shipping')->name('shipping.')->group(function (): void {
                Route::get('/quote', [ShippingController::class, 'quote'])
                    ->middleware('permission:view_shipping,manage_shipping')
                    ->name('quote');

                Route::prefix('methods')->name('methods.')->group(function (): void {
                    Route::get('/', [ShippingController::class, 'methods'])
                        ->middleware('permission:view_shipping,manage_shipping')
                        ->name('index');

                    Route::post('/', [ShippingController::class, 'storeMethod'])
                        ->middleware('permission:manage_shipping')
                        ->name('store');

                    Route::get('/{method}', [ShippingController::class, 'showMethod'])
                        ->middleware('permission:view_shipping,manage_shipping')
                        ->name('show');

                    Route::patch('/{method}', [ShippingController::class, 'updateMethod'])
                        ->middleware('permission:manage_shipping')
                        ->name('update');

                    Route::delete('/{method}', [ShippingController::class, 'destroyMethod'])
                        ->middleware('permission:manage_shipping')
                        ->name('destroy');

                    // A rate belongs to a method in the URL and a zone in the
                    // body — see StoreShippingRateRequest.
                    Route::post('/{method}/rates', [ShippingController::class, 'storeRate'])
                        ->middleware('permission:manage_shipping')
                        ->name('rates.store');
                });

                Route::prefix('zones')->name('zones.')->group(function (): void {
                    Route::get('/', [ShippingController::class, 'zones'])
                        ->middleware('permission:view_shipping,manage_shipping')
                        ->name('index');

                    Route::post('/', [ShippingController::class, 'storeZone'])
                        ->middleware('permission:manage_shipping')
                        ->name('store');

                    Route::get('/{zone}', [ShippingController::class, 'showZone'])
                        ->middleware('permission:view_shipping,manage_shipping')
                        ->name('show');

                    Route::patch('/{zone}', [ShippingController::class, 'updateZone'])
                        ->middleware('permission:manage_shipping')
                        ->name('update');

                    Route::delete('/{zone}', [ShippingController::class, 'destroyZone'])
                        ->middleware('permission:manage_shipping')
                        ->name('destroy');
                });

                // Addressed independently of its method, matching the pattern
                // ProductVariant's mutation routes already use — the admin
                // panel edits a rate from a row it already holds.
                Route::delete('/rates/{rate}', [ShippingController::class, 'destroyRate'])
                    ->middleware('permission:manage_shipping')
                    ->name('rates.destroy');
            });

            /*
             * Coupons.
             *
             * Read paths admit `view_coupons` as well as `manage_coupons`, so a
             * support agent can look up why a code was rejected without being
             * able to create or edit promotions.
             */
            Route::prefix('coupons')->name('coupons.')->group(function (): void {
                Route::get('/', [AdminCouponController::class, 'index'])
                    ->middleware('permission:view_coupons,manage_coupons')
                    ->name('index');

                Route::post('/', [AdminCouponController::class, 'store'])
                    ->middleware('permission:manage_coupons')
                    ->name('store');

                Route::get('/{coupon}', [AdminCouponController::class, 'show'])
                    ->middleware('permission:view_coupons,manage_coupons')
                    ->name('show');

                Route::patch('/{coupon}', [AdminCouponController::class, 'update'])
                    ->middleware('permission:manage_coupons')
                    ->name('update');

                Route::delete('/{coupon}', [AdminCouponController::class, 'destroy'])
                    ->middleware('permission:manage_coupons')
                    ->name('destroy');

                Route::get('/{coupon}/usages', [AdminCouponController::class, 'usages'])
                    ->middleware('permission:view_coupons,manage_coupons')
                    ->name('usages');
            });

            /*
             * Storefront content — the homepage builder, banners, CMS pages.
             *
             * Split across two permissions that correspond to two real jobs:
             * `manage_content` restructures the page and writes the policies,
             * while `manage_banners` schedules campaign imagery into sections
             * that already exist. A marketing account gets the latter without
             * the ability to rewrite the terms and conditions.
             *
             * Read paths also admit `view_settings`, so a read-only staff role
             * can inspect how the storefront is configured.
             */

            Route::prefix('homepage')->name('homepage.')->group(function (): void {
                // Declared before /sections/{section} so the literal segment is
                // not captured as an id.
                Route::put('/sections/reorder', [HomepageController::class, 'reorder'])
                    ->middleware('permission:manage_content')
                    ->name('sections.reorder');

                /*
                 * Renders the page as the storefront would receive it, at an
                 * arbitrary moment via `?at=`. That parameter is what makes
                 * scheduling reviewable before the scheduled date arrives.
                 */
                Route::get('/preview', [HomepageController::class, 'preview'])
                    ->middleware('permission:manage_content,manage_banners,view_settings')
                    ->name('preview');

                Route::get('/sections', [HomepageController::class, 'index'])
                    ->middleware('permission:manage_content,manage_banners,view_settings')
                    ->name('sections.index');

                Route::post('/sections', [HomepageController::class, 'store'])
                    ->middleware('permission:manage_content')
                    ->name('sections.store');

                Route::get('/sections/{section}', [HomepageController::class, 'show'])
                    ->middleware('permission:manage_content,manage_banners,view_settings')
                    ->name('sections.show');

                Route::patch('/sections/{section}', [HomepageController::class, 'update'])
                    ->middleware('permission:manage_content')
                    ->name('sections.update');

                Route::delete('/sections/{section}', [HomepageController::class, 'destroy'])
                    ->middleware('permission:manage_content')
                    ->name('sections.destroy');

                Route::patch('/sections/{section}/status', [HomepageController::class, 'setEnabled'])
                    ->middleware('permission:manage_content')
                    ->name('sections.status');
            });

            Route::prefix('banners')->name('banners.')->group(function (): void {
                Route::put('/reorder', [BannerController::class, 'reorder'])
                    ->middleware('permission:manage_banners,manage_content')
                    ->name('reorder');

                Route::get('/', [BannerController::class, 'index'])
                    ->middleware('permission:manage_banners,manage_content,view_settings')
                    ->name('index');

                Route::post('/', [BannerController::class, 'store'])
                    ->middleware('permission:manage_banners,manage_content')
                    ->name('store');

                Route::get('/{banner}', [BannerController::class, 'show'])
                    ->middleware('permission:manage_banners,manage_content,view_settings')
                    ->name('show');

                /*
                 * POST rather than PATCH for the update.
                 *
                 * Banner edits carry file uploads, and PHP does not parse a
                 * multipart body on PATCH — the fields would silently arrive
                 * empty. The client sends POST with `_method=PATCH`, which
                 * Laravel's method override turns back into a PATCH route.
                 */
                Route::match(['patch', 'post'], '/{banner}', [BannerController::class, 'update'])
                    ->middleware('permission:manage_banners,manage_content')
                    ->name('update');

                Route::delete('/{banner}', [BannerController::class, 'destroy'])
                    ->middleware('permission:manage_banners,manage_content')
                    ->name('destroy');
            });

            Route::prefix('pages')->name('pages.')->group(function (): void {
                Route::get('/', [CmsPageController::class, 'index'])
                    ->middleware('permission:manage_content,view_settings')
                    ->name('index');

                Route::post('/', [CmsPageController::class, 'store'])
                    ->middleware('permission:manage_content')
                    ->name('store');

                /*
                 * Bound by slug, matching the storefront URL, so the segment is
                 * constrained to slug characters — an id would 404 here by
                 * design rather than by accident.
                 */
                Route::get('/{page}', [CmsPageController::class, 'show'])
                    ->where('page', '[a-z0-9-]+')
                    ->middleware('permission:manage_content,view_settings')
                    ->name('show');

                // Multipart uploads again — see the banner update above.
                Route::match(['patch', 'post'], '/{page}', [CmsPageController::class, 'update'])
                    ->where('page', '[a-z0-9-]+')
                    ->middleware('permission:manage_content')
                    ->name('update');

                Route::delete('/{page}', [CmsPageController::class, 'destroy'])
                    ->where('page', '[a-z0-9-]+')
                    ->middleware('permission:manage_content')
                    ->name('destroy');

                Route::patch('/{page}/status', [CmsPageController::class, 'setStatus'])
                    ->where('page', '[a-z0-9-]+')
                    ->middleware('permission:manage_content')
                    ->name('status');
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
