<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ChangePasswordRequest;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\AdminResource;
use App\Models\Admin;
use App\Services\AdminAuthService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff authentication endpoints.
 *
 * There is deliberately no registration endpoint: staff accounts are created
 * only by an existing administrator holding `manage_admins`. Self-registration
 * into a privileged table would be an obvious escalation path.
 */
final class AdminAuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AdminAuthService $auth,
    ) {
    }

    /**
     * POST /admin/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login($request->email(), $request->password(), $request->ip());

        /** @var Admin $admin */
        $admin = $result['admin'];

        return $this->successResponse(
            data: [
                // Permissions are included on login so the panel can render
                // its navigation immediately, without a second round-trip.
                'admin' => (new AdminResource($admin->load('roles.permissions')))->withPermissions(),
                'token' => $result['token'],
                'token_type' => 'Bearer',
                'expires_at' => $result['expires_at'],
                // Signals the client to route straight to the change-password
                // screen; every other endpoint will 403 until it is satisfied.
                'must_change_password' => $result['must_change_password'],
            ],
            message: 'Login successful.',
        );
    }

    /**
     * POST /admin/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();

        $this->auth->logout($admin);

        return $this->successResponse(message: 'Logged out successfully.');
    }

    /**
     * POST /admin/auth/logout-all
     */
    public function logoutAll(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();

        $this->auth->logoutEverywhere($admin);

        return $this->successResponse(message: 'Logged out on all devices.');
    }

    /**
     * GET /admin/auth/me
     *
     * The frontend calls this on load to rebuild its permission state, so a
     * role change takes effect on refresh rather than requiring a re-login.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();

        return $this->successResponse(
            data: (new AdminResource($admin->load('roles.permissions')))->withPermissions(),
            message: 'Profile retrieved successfully.',
        );
    }

    /**
     * POST /admin/auth/change-password
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();

        $this->auth->changePassword($admin, $request->currentPassword(), $request->newPassword());

        return $this->successResponse(
            message: 'Password changed successfully. Other sessions have been signed out.',
        );
    }
}
