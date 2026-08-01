<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Services\PasswordResetService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Password reset for customers and staff.
 *
 * The "forgot" endpoints return an identical success response whether or not
 * the address is registered. Anything else — a 404, a different message, even
 * a measurably different response time — would let an attacker enumerate
 * which addresses have accounts.
 */
final class PasswordResetController extends Controller
{
    use ApiResponse;

    /** Returned for every forgot-password request, regardless of outcome. */
    private const GENERIC_SENT_MESSAGE = 'If an account exists for that email address, a password reset link has been sent.';

    public function __construct(
        private readonly PasswordResetService $passwordReset,
    ) {
    }

    /**
     * POST /auth/forgot-password
     */
    public function sendCustomerResetLink(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwordReset->sendCustomerResetLink($request->email());

        return $this->successResponse(message: self::GENERIC_SENT_MESSAGE);
    }

    /**
     * POST /auth/reset-password
     */
    public function resetCustomerPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->passwordReset->resetCustomerPassword(
            (string) $request->validated('email'),
            (string) $request->validated('token'),
            (string) $request->validated('password'),
        );

        return $this->successResponse(
            message: 'Password reset successfully. Please sign in with your new password.',
        );
    }

    /**
     * POST /admin/auth/forgot-password
     */
    public function sendAdminResetLink(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwordReset->sendAdminResetLink($request->email());

        return $this->successResponse(message: self::GENERIC_SENT_MESSAGE);
    }

    /**
     * POST /admin/auth/reset-password
     */
    public function resetAdminPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->passwordReset->resetAdminPassword(
            (string) $request->validated('email'),
            (string) $request->validated('token'),
            (string) $request->validated('password'),
        );

        return $this->successResponse(
            message: 'Password reset successfully. Please sign in with your new password.',
        );
    }
}
