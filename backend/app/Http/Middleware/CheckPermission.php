<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level permission gate.
 *
 * Usage:
 *   ->middleware('permission:manage_admins')            single
 *   ->middleware('permission:view_orders,view_payments') any of
 *   ->middleware('permission:view_orders,update_orders,all') all of
 *
 * Route middleware is the coarse gate ("may this actor reach this endpoint at
 * all?"); policies handle the per-record question ("may they act on *this*
 * record?"). Both are needed — middleware alone cannot express "only if you
 * outrank the target", and policies alone leave the endpoint reachable.
 */
final class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $admin = $request->user();

        if (! $admin instanceof Admin) {
            return $this->deny('Administrator authentication is required.', 'ADMIN_AUTH_REQUIRED', 401, []);
        }

        if ($permissions === []) {
            return $next($request);
        }

        // A trailing "all" switches the check from any-of to all-of.
        $requireAll = end($permissions) === 'all';

        if ($requireAll) {
            array_pop($permissions);
        }

        $authorized = $requireAll
            ? $admin->hasAllPermissions($permissions)
            : $admin->hasAnyPermission($permissions);

        if (! $authorized) {
            // Denials on staff routes are logged: a burst of them is a strong
            // signal of either a broken UI or someone probing the API.
            Log::notice('Admin permission check failed.', [
                'admin_uuid' => $admin->uuid,
                'required' => $permissions,
                'mode' => $requireAll ? 'all' : 'any',
                'route' => $request->path(),
            ]);

            return $this->deny(
                'You do not have permission to perform this action.',
                'INSUFFICIENT_PERMISSIONS',
                403,
                $permissions,
            );
        }

        return $next($request);
    }

    /**
     * @param  array<int, string>  $required
     */
    private function deny(string $message, string $code, int $status, array $required): Response
    {
        $payload = [
            'success' => false,
            'message' => $message,
            'code' => $code,
        ];

        // Naming the missing permission is safe — the caller is authenticated
        // staff — and makes an over-restrictive role obvious to whoever is
        // debugging it.
        if ($required !== []) {
            $payload['required_permissions'] = array_values($required);
        }

        return response()->json($payload, $status);
    }
}
