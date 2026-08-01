<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Email verification for customers.
 *
 * The verify endpoint is reached from a link in an email, so it is opened by a
 * browser with no bearer token. It authenticates via Laravel's signed-URL
 * mechanism instead, then redirects to the storefront — returning JSON to a
 * browser address bar would show the user a wall of raw text.
 */
final class EmailVerificationController extends Controller
{
    use ApiResponse;

    /**
     * GET /auth/verify-email/{id}/{hash}
     *
     * Protected by the `signed` middleware, which rejects a tampered or
     * expired URL before this method runs.
     */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        /** @var User|null $user */
        $user = User::query()->find($id);

        if ($user === null) {
            return $this->redirectToFrontend('invalid');
        }

        // The signature proves the URL was issued by us; this proves it was
        // issued for *this* address. Without it, a signed link would remain
        // valid after the user changed their email.
        if (! hash_equals($hash, sha1((string) $user->getEmailForVerification()))) {
            return $this->redirectToFrontend('invalid');
        }

        if ($user->hasVerifiedEmail()) {
            return $this->redirectToFrontend('already-verified');
        }

        $user->markEmailAsVerified();

        event(new Verified($user));

        // Tokens issued before verification carry only the narrow
        // `customer:unverified` ability. They are revoked so the next sign-in
        // mints a full-access token rather than leaving the user stuck with a
        // restricted one until it expires.
        $user->tokens()->delete();

        return $this->redirectToFrontend('verified');
    }

    /**
     * POST /auth/email/resend
     *
     * Available to an authenticated but unverified customer.
     */
    public function resend(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return $this->successResponse(message: 'Your email address is already verified.');
        }

        $user->sendEmailVerificationNotification();

        return $this->successResponse(message: 'A new verification link has been sent to your email address.');
    }

    /**
     * GET /auth/email/status
     */
    public function status(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->successResponse(
            data: [
                'email' => $user->email,
                'verified' => $user->hasVerifiedEmail(),
                'verified_at' => $user->email_verified_at?->toIso8601String(),
            ],
            message: 'Verification status retrieved.',
        );
    }

    /**
     * Hand the browser back to the storefront with the outcome in the query
     * string, so the React page can render an appropriate message.
     */
    private function redirectToFrontend(string $status): RedirectResponse
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        return redirect()->away("{$base}/verify-email?status={$status}");
    }
}
