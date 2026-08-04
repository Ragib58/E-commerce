<?php

declare(strict_types=1);

use App\Http\Middleware\ApiVersion;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnsureAdminIsActive;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsurePasswordIsCurrent;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\ResolveCart;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        then: function () {
            Illuminate\Support\Facades\Route::middleware('web')
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global: every request gets a correlation id for log tracing.
        $middleware->append(RequestId::class);

        // The `api` group is stateless — token authentication only. Sanctum's
        // stateful (cookie) middleware is deliberately NOT registered: the
        // storefront and admin panel both authenticate with bearer tokens, and
        // enabling cookie auth would add a CSRF surface for no benefit.
        $middleware->api(prepend: [
            ForceJsonResponse::class,
            ApiVersion::class,
        ]);

        $middleware->alias([
            'api.version' => ApiVersion::class,

            // Authorization. `admin.active` revalidates the account on every
            // request so deactivation takes effect immediately rather than
            // when the token happens to expire.
            'admin.active' => EnsureAdminIsActive::class,
            'admin.password' => EnsurePasswordIsCurrent::class,
            'permission' => CheckPermission::class,
            'role' => CheckRole::class,

            // Overrides Laravel's built-in `verified`, which returns a bare
            // 403 that does not carry this project's error envelope.
            'verified' => EnsureEmailIsVerified::class,

            /*
             * Resolves the shopping cart for a request — by user id when
             * signed in, by the X-Cart-Token header when not.
             *
             * Applied per-route rather than to the whole api group: it hits the
             * database, and the catalog, settings, and health endpoints have no
             * use for a cart.
             */
            'cart' => ResolveCart::class,
        ]);

        // Admin panel routes are session-based; keep CSRF on, exclude nothing.
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Exception rendering for the API is centralised in the handler class
        // so the same envelope is produced for every failure mode.
        App\Exceptions\ApiExceptionHandler::register($exceptions);
    })
    ->create();
