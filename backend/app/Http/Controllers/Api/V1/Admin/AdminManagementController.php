<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AssignPermissionsRequest;
use App\Http\Requests\Api\V1\Admin\AssignRolesRequest;
use App\Http\Requests\Api\V1\Admin\StoreAdminRequest;
use App\Http\Requests\Api\V1\Admin\UpdateAdminRequest;
use App\Http\Resources\Api\V1\AdminResource;
use App\Models\Admin;
use App\Services\AdminManagementService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff account administration.
 *
 * Authorization runs at three layers, each catching what the others cannot:
 *   1. Route middleware — does this account hold `manage_admins` at all?
 *   2. Policy — may this actor act on *this* target, given their ranks?
 *   3. Service — do the requested roles and permissions stay within what the
 *      actor may delegate?
 *
 * The third is not expressible in a policy, which never sees the requested
 * role list.
 */
final class AdminManagementController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AdminManagementService $admins,
    ) {
    }

    /**
     * GET /admin/admins
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Admin::class);

        $perPage = min(
            (int) $request->integer('per_page', (int) config('api.pagination.per_page')),
            (int) config('api.pagination.max_per_page'),
        );

        $admins = Admin::query()
            ->with(['roles'])
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = (string) $request->string('search');

                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('role'), function ($query) use ($request): void {
                $query->withRole((string) $request->string('role'));
            })
            ->when($request->has('is_active'), function ($query) use ($request): void {
                $query->where('is_active', $request->boolean('is_active'));
            })
            ->orderBy('name')
            ->paginate($perPage);

        return $this->successResponse(
            data: AdminResource::collection($admins),
            message: 'Administrators retrieved successfully.',
        );
    }

    /**
     * GET /admin/admins/{admin}
     */
    public function show(Admin $admin): JsonResponse
    {
        $this->authorize('view', $admin);

        return $this->successResponse(
            data: new AdminResource($admin->load(['roles.permissions', 'directPermissions'])),
            message: 'Administrator retrieved successfully.',
        );
    }

    /**
     * POST /admin/admins
     */
    public function store(StoreAdminRequest $request): JsonResponse
    {
        /** @var Admin $actor */
        $actor = $request->user();

        $result = $this->admins->create($request->payload(), $actor);

        $payload = ['admin' => new AdminResource($result['admin'])];

        // A generated password is returned exactly once, at creation. It is
        // never stored in plaintext and cannot be retrieved again — the
        // recipient must reset it if this response is lost.
        if ($result['generated_password'] !== null) {
            $payload['generated_password'] = $result['generated_password'];
            $payload['password_notice'] = 'This password is shown only once and must be changed at first sign-in.';
        }

        return $this->successResponse(
            data: $payload,
            message: 'Administrator created successfully.',
            status: 201,
        );
    }

    /**
     * PATCH /admin/admins/{admin}
     */
    public function update(UpdateAdminRequest $request, Admin $admin): JsonResponse
    {
        /** @var Admin $actor */
        $actor = $request->user();

        $updated = $this->admins->update($admin, $request->validated(), $actor);

        return $this->successResponse(
            data: new AdminResource($updated),
            message: 'Administrator updated successfully.',
        );
    }

    /**
     * DELETE /admin/admins/{admin}
     */
    public function destroy(Request $request, Admin $admin): JsonResponse
    {
        $this->authorize('delete', $admin);

        /** @var Admin $actor */
        $actor = $request->user();

        $this->admins->delete($admin, $actor);

        return $this->successResponse(message: 'Administrator deleted successfully.');
    }

    /**
     * PUT /admin/admins/{admin}/roles
     */
    public function assignRoles(AssignRolesRequest $request, Admin $admin): JsonResponse
    {
        /** @var Admin $actor */
        $actor = $request->user();

        $updated = $this->admins->assignRoles($admin, $request->roles(), $actor);

        return $this->successResponse(
            data: new AdminResource($updated),
            message: 'Roles updated successfully.',
        );
    }

    /**
     * PUT /admin/admins/{admin}/permissions
     */
    public function assignPermissions(AssignPermissionsRequest $request, Admin $admin): JsonResponse
    {
        /** @var Admin $actor */
        $actor = $request->user();

        $updated = $this->admins->assignPermissions($admin, $request->permissions(), $actor);

        return $this->successResponse(
            data: new AdminResource($updated),
            message: 'Permissions updated successfully.',
        );
    }

    /**
     * PATCH /admin/admins/{admin}/status
     */
    public function setStatus(Request $request, Admin $admin): JsonResponse
    {
        $this->authorize('activate', $admin);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        /** @var Admin $actor */
        $actor = $request->user();

        $updated = $this->admins->setActive($admin, (bool) $validated['is_active'], $actor);

        return $this->successResponse(
            data: new AdminResource($updated),
            message: $updated->is_active
                ? 'Administrator activated successfully.'
                : 'Administrator deactivated successfully.',
        );
    }
}
