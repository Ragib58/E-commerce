<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks routes that require a verified customer email.
 *
 * Laravel ships an equivalent, but its API variant returns a bare 403 that
 * does not fit this project's error envelope and gives the frontend no
 * machine-readable code to branch on. This returns `EMAIL_NOT_VERIFIED`, which
 * the storefront uses to render a "resend verification" prompt rather than a
 * generic permission error.
 */
final class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'message' => 'Please verify your email address to continue.',
                'code' => 'EMAIL_NOT_VERIFIED',
            ], 403);
        }

        return $next($request);
    }
}
