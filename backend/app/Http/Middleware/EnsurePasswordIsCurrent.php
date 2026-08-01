<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces a password rotation before a staff member can use the panel.
 *
 * Set when a Super Admin creates an account with a generated password. That
 * password necessarily passed through a third party — it was read off a
 * screen, pasted into a chat, or emailed — so it must not remain valid as a
 * long-term credential.
 *
 * Applied to the admin route group as a whole and skipped for the endpoints
 * needed to escape the gate (change-password, profile read, logout);
 * otherwise the requirement would be impossible to satisfy.
 */
final class EnsurePasswordIsCurrent
{
    /**
     * Route names exempt from the check, so an admin can actually comply.
     *
     * @var array<int, string>
     */
    private const EXEMPT_ROUTES = [
        'api.v1.admin.auth.change-password',
        'api.v1.admin.auth.me',
        'api.v1.admin.auth.logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();

        if (! $admin instanceof Admin || ! $admin->must_change_password) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::EXEMPT_ROUTES, strict: true)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'You must change your password before continuing.',
            'code' => 'PASSWORD_CHANGE_REQUIRED',
        ], 403);
    }
}
