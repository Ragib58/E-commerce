<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PermissionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PermissionResource;
use App\Http\Resources\Api\V1\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read access to roles and permissions.
 *
 * Needed by the admin panel to populate role pickers and the permission
 * matrix. Creating and editing roles is a later phase; the policy that would
 * govern it (RolePolicy) already exists.
 */
final class RoleController extends Controller
{
    use ApiResponse;

    /**
     * GET /admin/roles
     *
     * Returns only roles the caller may actually assign, so the UI cannot
     * offer an option the API would then reject.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        /** @var \App\Models\Admin $actor */
        $actor = $request->user();

        $roles = Role::query()
            ->withCount('permissions')
            ->assignable()
            ->get();

        // Super Admin may assign anything; everyone else sees only strictly
        // lower ranks.
        if (! $actor->isSuperAdmin()) {
            $actorLevel = $actor->roleLevel();

            $roles = $roles->filter(
                static fn (Role $role): bool => $role->level < $actorLevel
            )->values();
        }

        return $this->successResponse(
            data: RoleResource::collection($roles),
            message: 'Roles retrieved successfully.',
        );
    }

    /**
     * GET /admin/roles/{role}
     */
    public function show(Role $role): JsonResponse
    {
        $this->authorize('view', $role);

        return $this->successResponse(
            data: new RoleResource($role->load('permissions')),
            message: 'Role retrieved successfully.',
        );
    }

    /**
     * GET /admin/permissions
     *
     * Grouped, so the panel can render the permission matrix by section
     * without hardcoding the grouping in the frontend.
     */
    public function permissions(): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $permissions = Permission::query()
            ->orderBy('group')
            ->orderBy('label')
            ->get()
            ->groupBy('group')
            ->map(fn ($group) => PermissionResource::collection($group));

        return $this->successResponse(
            data: $permissions,
            message: 'Permissions retrieved successfully.',
            meta: ['groups' => array_keys(PermissionType::grouped())],
        );
    }
}
