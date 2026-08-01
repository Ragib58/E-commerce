<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ChangePasswordRequest;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\CustomerAuthService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer registration, session, and profile endpoints.
 *
 * Controllers here orchestrate only: they call the service, wrap the result in
 * a resource, and return. Credential handling, token issuance, and session
 * revocation all live in CustomerAuthService.
 */
final class CustomerAuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CustomerAuthService $auth,
    ) {
    }

    /**
     * POST /auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->auth->register($request->payload(), $request->ip());

        return $this->successResponse(
            data: [
                'user' => new UserResource($result['user']),
                'token' => $result['token'],
                'token_type' => 'Bearer',
                'expires_at' => $result['expires_at'],
            ],
            message: 'Registration successful. Please check your email to verify your address.',
            status: 201,
        );
    }

    /**
     * POST /auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login($request->email(), $request->password(), $request->ip());

        return $this->successResponse(
            data: [
                'user' => new UserResource($result['user']),
                'token' => $result['token'],
                'token_type' => 'Bearer',
                'expires_at' => $result['expires_at'],
            ],
            message: 'Login successful.',
        );
    }

    /**
     * POST /auth/logout
     *
     * Revokes only the token used for this request, so signing out on one
     * device leaves other devices signed in.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->auth->logout($user);

        return $this->successResponse(message: 'Logged out successfully.');
    }

    /**
     * POST /auth/logout-all
     */
    public function logoutAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->auth->logoutEverywhere($user);

        return $this->successResponse(message: 'Logged out on all devices.');
    }

    /**
     * GET /auth/me
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->successResponse(
            data: new UserResource($user),
            message: 'Profile retrieved successfully.',
        );
    }

    /**
     * PATCH /auth/profile
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $updated = $this->auth->updateProfile($user, $request->validated());

        return $this->successResponse(
            data: new UserResource($updated),
            message: 'Profile updated successfully.',
        );
    }

    /**
     * POST /auth/change-password
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->auth->changePassword($user, $request->currentPassword(), $request->newPassword());

        return $this->successResponse(
            message: 'Password changed successfully. Other sessions have been signed out.',
        );
    }
}
