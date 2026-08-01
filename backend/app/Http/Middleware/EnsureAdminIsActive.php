<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\TokenAbility;
use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Revalidates a staff token on every request.
 *
 * A Sanctum token remains cryptographically valid until it expires or is
 * deleted, so authentication alone does not prove the account is still
 * permitted to act. This re-checks the live account state each request, which
 * is what makes deactivation take effect immediately rather than whenever the
 * token happens to lapse.
 *
 * Three checks, each closing a different gap:
 *   1. The principal really is an Admin — not a customer token that somehow
 *      reached an admin route.
 *   2. The token carries the admin ability.
 *   3. The account is still active and not soft-deleted.
 */
final class EnsureAdminIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();

        // Belt and braces: the `auth:admin-api` guard should already have
        // rejected a non-Admin principal, because its provider queries a
        // different table. Asserting the type here means a future routing
        // mistake produces a 403 rather than a privilege escalation.
        if (! $admin instanceof Admin) {
            return $this->deny('Administrator authentication is required.', 'ADMIN_AUTH_REQUIRED', 401);
        }

        $token = $admin->currentAccessToken();

        if ($token !== null && ! $admin->tokenCan(TokenAbility::AdminAccess->value)) {
            Log::warning('Admin route accessed with a token lacking the admin ability.', [
                'admin_uuid' => $admin->uuid,
                'ip' => $request->ip(),
            ]);

            return $this->deny('This token is not valid for administrator access.', 'INVALID_TOKEN_ABILITY', 403);
        }

        if (! $admin->canAuthenticate()) {
            // The account was deactivated or deleted while this token was
            // still live. Destroy the token so the next request fails at
            // authentication rather than repeating this check.
            $token?->delete();

            Log::warning('Request from a deactivated admin account was blocked.', [
                'admin_uuid' => $admin->uuid,
                'ip' => $request->ip(),
            ]);

            return $this->deny('This account has been deactivated.', 'ACCOUNT_DEACTIVATED', 403);
        }

        return $next($request);
    }

    private function deny(string $message, string $code, int $status): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'code' => $code,
        ], $status);
    }
}
