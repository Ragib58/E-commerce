<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
                ->response(fn (): \Illuminate\Http\JsonResponse => response()->json([
                    'success' => false,
                    'message' => 'Too many requests. Please slow down.',
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
                ->by($user::class . ':' . $user->getAuthIdentifier());
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
                    ->by($email . '|' . $request->ip())
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
                    ->by($email . '|' . $request->ip())
                    ->response(fn (): JsonResponse => self::throttledResponse(
                        'Too many attempts. Please wait before trying again.'
                    )),

                Limit::perMinute((int) config('api.rate_limits.admin_auth_per_ip'))
                    ->by('admin-ip:' . $request->ip())
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
                ->by($email . '|' . $request->ip())
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
                ->by('verify:' . $key)
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
