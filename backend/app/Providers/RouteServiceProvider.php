<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\ResolveCart;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Defines the named rate limiters referenced by route middleware.
 *
 * Limits live in config/api.php rather than as literals here, so they are
 * tunable per environment without a code change.
 */
final class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureRateLimiters();
        $this->configureModelBindings();
    }

    /**
     * Bind catalog models by primary key on admin routes.
     *
     * The models resolve by slug for the storefront, which is what makes
     * `/products/blue-shirt` work. The admin panel must not inherit that: an
     * admin editing a product's slug would invalidate the very URL they are
     * editing it from, and a draft has no stable slug at all.
     *
     * ## An explicit binding applies to every route using the parameter name
     *
     * `Route::bind()` is global — it fires for any `{product}` segment
     * anywhere, regardless of what the controller asked for. That is a trap,
     * and it bit `DELETE /wishlist/{product}`: that action takes a `string`
     * and resolves the identifier itself so it can answer with a friendly 422,
     * but the binder ran first, failed its slug lookup on a uuid, and turned
     * the whole route into a 404.
     *
     * {@see routeWantsModel()} is the guard: a route whose controller
     * type-hints the model gets a resolved model, and one that type-hints a
     * string gets the raw string. The binding stays where it is useful and
     * stops silently rewriting routes that never asked for it.
     */
    private function configureModelBindings(): void
    {
        $bindings = [
            'product' => Product::class,
            'category' => Category::class,
            'brand' => Brand::class,
        ];

        foreach ($bindings as $parameter => $model) {
            Route::bind($parameter, function (string $value) use ($parameter, $model): mixed {
                // The controller wants the identifier, not the record.
                if (! $this->routeWantsModel($parameter, $model)) {
                    return $value;
                }

                /*
                 * Matched on the request path rather than the route name: the
                 * name is only available once the route is resolved, and the
                 * path is what actually distinguishes the two surfaces.
                 */
                $isAdminRequest = request()->is('api/*/admin/*') || request()->is('admin/*');

                if ($isAdminRequest) {
                    /*
                     * Admin URLs identify a record by something stable. A slug
                     * is not: an admin editing a product's slug would
                     * invalidate the very URL they are editing it from, and a
                     * draft may not have a meaningful one yet.
                     *
                     * Products expose a uuid publicly and keep their integer
                     * key private; categories and brands have no uuid, so the
                     * panel addresses them by integer id.
                     */
                    if (ctype_digit($value)) {
                        return $model::query()->whereKey((int) $value)->firstOrFail();
                    }

                    if (Str::isUuid($value)) {
                        return $model::query()->where('uuid', $value)->firstOrFail();
                    }
                }

                return $model::query()->where('slug', $value)->firstOrFail();
            });
        }
    }

    /**
     * Whether the current route's action actually type-hints this model for
     * the given parameter.
     *
     * Read from the controller signature rather than from a list of exempt
     * routes: a list has to be remembered on every new endpoint, and the
     * consequence of forgetting is a 404 that looks like a routing problem
     * rather than a binding one. The signature is the declaration of intent
     * that is already there.
     *
     * Anything unresolvable — a closure route, a missing method, a reflection
     * failure — falls back to `true`, preserving the existing behaviour for
     * every route that was working before.
     *
     * @param  class-string  $model
     */
    private function routeWantsModel(string $parameter, string $model): bool
    {
        $route = request()->route();

        if ($route === null) {
            return true;
        }

        try {
            $action = $route->getAction('uses');

            $reflection = match (true) {
                is_string($action) && str_contains($action, '@') => (function () use ($action): \ReflectionFunctionAbstract {
                    [$class, $method] = explode('@', $action, 2);

                    return new \ReflectionMethod($class, $method);
                })(),
                $action instanceof \Closure => new \ReflectionFunction($action),
                default => null,
            };

            if ($reflection === null) {
                return true;
            }

            foreach ($reflection->getParameters() as $candidate) {
                if ($candidate->getName() !== $parameter) {
                    continue;
                }

                $type = $candidate->getType();

                if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                    // Declared `string $product` — hand back the raw value.
                    return false;
                }

                return is_a($type->getName(), $model, allow_string: true);
            }
        } catch (\Throwable) {
            return true;
        }

        // The parameter is not in the signature at all, so nothing is expecting
        // a particular shape; resolve as before.
        return true;
    }

    private function configureRateLimiters(): void
    {
        // Default limiter for authenticated traffic; keyed by user id so one
        // noisy client cannot exhaust the budget for others behind the same NAT.
        RateLimiter::for('api', function (Request $request): Limit {
            return $request->user()
                ? Limit::perMinute((int) config('api.rate_limits.authenticated'))->by((string) $request->user()->getAuthIdentifier())
                : Limit::perMinute((int) config('api.rate_limits.public'))->by((string) $request->ip());
        });

        // Unauthenticated storefront reads.
        RateLimiter::for('public', function (Request $request): Limit {
            return Limit::perMinute((int) config('api.rate_limits.public'))
                ->by((string) $request->ip())
                ->response(fn (): JsonResponse => response()->json([
                    'success' => false,
                    'message' => 'Too many requests. Please slow down.',
                    'code' => 'RATE_LIMITED',
                ], 429));
        });

        /*
         * Cart mutations.
         *
         * Its own budget rather than sharing `public`, for two reasons. These
         * are writes available to unauthenticated visitors, so they are the
         * cheapest endpoint from which to create rows — a stricter ceiling
         * bounds how fast anyone can fill the carts table. And a shopper
         * legitimately clicking "+" repeatedly must not exhaust the budget that
         * the rest of the storefront's browsing depends on.
         *
         * Keyed on the authenticated user when there is one, falling back to
         * the cart token, then the IP. The token key matters: several guests
         * behind one NAT would otherwise share a single allowance.
         */
        RateLimiter::for('cart', function (Request $request): Limit {
            $user = $request->user();

            $key = $user !== null
                ? $user::class.':'.$user->getAuthIdentifier()
                : ($request->header(ResolveCart::HEADER) ?: (string) $request->ip());

            return Limit::perMinute((int) config('api.rate_limits.cart', 60))
                ->by($key)
                ->response(fn (): JsonResponse => response()->json([
                    'success' => false,
                    'message' => 'Too many cart updates. Please slow down.',
                    'code' => 'RATE_LIMITED',
                ], 429));
        });

        /*
         * Order placement — the tightest budget on the storefront.
         *
         * This is the one endpoint that opens a transaction, decrements stock,
         * and writes an order. A retry storm here is far more expensive than
         * one against a read, and unlike the rest of checkout there is no
         * legitimate reason to call it repeatedly: a shopper places an order
         * once.
         *
         * The limit is a backstop, not the duplicate-order defence. That is the
         * unique index on `orders.idempotency_key` — a rate limit slows a
         * double submission down, it does not make it safe.
         *
         * Keyed like the cart limiter: user, then checkout token, then IP, so
         * several guests behind one NAT do not share an allowance.
         */
        RateLimiter::for('checkout-place', function (Request $request): Limit {
            $user = $request->user();

            $key = $user !== null
                ? $user::class.':'.$user->getAuthIdentifier()
                : ($request->route('token') ?: (string) $request->ip());

            return Limit::perMinute((int) config('api.rate_limits.checkout_place', 10))
                ->by((string) $key)
                ->response(fn (): JsonResponse => response()->json([
                    'success' => false,
                    'message' => 'Too many attempts to place this order. Please wait a moment and try again.',
                    'code' => 'RATE_LIMITED',
                ], 429));
        });

        // Health probes get their own budget so a traffic spike never blinds
        // monitoring, and monitoring never eats into the public budget.
        RateLimiter::for('health', function (Request $request): Limit {
            return Limit::perMinute((int) config('api.rate_limits.health'))
                ->by((string) $request->ip());
        });

        // Authenticated traffic, keyed by the token owner. Both principals are
        // covered: an Admin and a User can share an id without colliding
        // because the key includes the class.
        RateLimiter::for('authenticated', function (Request $request): Limit {
            $user = $request->user();

            if ($user === null) {
                return Limit::perMinute((int) config('api.rate_limits.public'))->by((string) $request->ip());
            }

            return Limit::perMinute((int) config('api.rate_limits.authenticated'))
                ->by($user::class.':'.$user->getAuthIdentifier());
        });

        /*
         * Credential endpoints — login and registration.
         *
         * Keyed on email+IP rather than either alone. Keying on IP only would
         * let a distributed attack try one password per address against one
         * account indefinitely; keying on email only would let an attacker
         * lock a victim out of their own account by deliberately failing.
         * The pair limits both without either failure mode.
         */
        RateLimiter::for('auth', function (Request $request): array {
            $email = strtolower((string) $request->input('email', ''));

            return [
                Limit::perMinute((int) config('api.rate_limits.auth'))
                    ->by($email.'|'.$request->ip())
                    ->response(fn (): JsonResponse => self::throttledResponse(
                        'Too many attempts. Please wait before trying again.'
                    )),

                // Second, wider limit catching an attacker who rotates the
                // email to stay under the per-pair limit from one address.
                Limit::perMinute((int) config('api.rate_limits.auth_per_ip'))
                    ->by((string) $request->ip())
                    ->response(fn (): JsonResponse => self::throttledResponse(
                        'Too many attempts from this network. Please wait before trying again.'
                    )),
            ];
        });

        // Staff login is tighter still: there are few legitimate staff and a
        // successful attack costs far more than one customer account.
        RateLimiter::for('admin-auth', function (Request $request): array {
            $email = strtolower((string) $request->input('email', ''));

            return [
                Limit::perMinute((int) config('api.rate_limits.admin_auth'))
                    ->by($email.'|'.$request->ip())
                    ->response(fn (): JsonResponse => self::throttledResponse(
                        'Too many attempts. Please wait before trying again.'
                    )),

                Limit::perMinute((int) config('api.rate_limits.admin_auth_per_ip'))
                    ->by('admin-ip:'.$request->ip())
                    ->response(fn (): JsonResponse => self::throttledResponse(
                        'Too many attempts from this network.'
                    )),
            ];
        });

        // Each request sends an email, so this limit protects mailboxes and
        // sender reputation as much as it protects the application.
        RateLimiter::for('password-reset', function (Request $request): Limit {
            $email = strtolower((string) $request->input('email', ''));

            return Limit::perMinutes(
                (int) config('api.rate_limits.password_reset_window'),
                (int) config('api.rate_limits.password_reset'),
            )
                ->by($email.'|'.$request->ip())
                ->response(fn (): JsonResponse => self::throttledResponse(
                    'Too many password reset requests. Please check your inbox, then try again later.'
                ));
        });

        RateLimiter::for('verification', function (Request $request): Limit {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinutes(
                (int) config('api.rate_limits.verification_window'),
                (int) config('api.rate_limits.verification'),
            )
                ->by('verify:'.$key)
                ->response(fn (): JsonResponse => self::throttledResponse(
                    'Too many verification requests. Please wait before requesting another link.'
                ));
        });
    }

    /**
     * Throttle rejections must carry the project's error envelope; Laravel's
     * default 429 body does not.
     */
    private static function throttledResponse(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'code' => 'RATE_LIMITED',
        ], 429);
    }
}
