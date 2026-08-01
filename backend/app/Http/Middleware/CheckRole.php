<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level role gate.
 *
 * Deliberately rare in this codebase. Permission checks are preferred almost
 * everywhere, because they survive an operator reorganising roles from the
 * admin panel — `permission:manage_admins` keeps working when a new role is
 * granted that permission, whereas `role:super_admin` does not.
 *
 * Reach for this only where the *role itself* is the requirement, such as an
 * endpoint reserved structurally for Super Admin.
 */
final class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $admin = $request->user();

        if (! $admin instanceof Admin) {
            return response()->json([
                'success' => false,
                'message' => 'Administrator authentication is required.',
                'code' => 'ADMIN_AUTH_REQUIRED',
            ], 401);
        }

        if ($roles !== [] && ! $admin->hasAnyRole($roles)) {
            return response()->json([
                'success' => false,
                'message' => 'Your role does not permit this action.',
                'code' => 'INSUFFICIENT_ROLE',
            ], 403);
        }

        return $next($request);
    }
}
