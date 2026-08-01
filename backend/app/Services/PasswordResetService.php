<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Password reset for both principals, via their separate brokers.
 *
 * The broker name is the only difference between the two flows, so this is
 * parameterised rather than duplicated — unlike login, where the flows differ
 * substantively.
 *
 * Account enumeration is the central concern here. The "send link" endpoint
 * returns an identical response whether or not the address exists; anything
 * else turns the endpoint into an oracle for which emails are registered.
 */
final class PasswordResetService
{
    private const BROKER_CUSTOMER = 'users';

    private const BROKER_ADMIN = 'admins';

    public function sendCustomerResetLink(string $email): void
    {
        $this->sendResetLink(self::BROKER_CUSTOMER, $email);
    }

    public function sendAdminResetLink(string $email): void
    {
        $this->sendResetLink(self::BROKER_ADMIN, $email);
    }

    /**
     * Dispatch a reset link, swallowing "user not found".
     *
     * NOTHING about the outcome is surfaced — not "no such user", and not
     * "throttled" either.
     *
     * The throttle case is the subtle one. Laravel's broker can only throttle
     * an address it actually found, so a registered address returns
     * RESET_THROTTLED on a rapid second request while an unregistered one
     * silently returns INVALID_USER. Reporting the throttle therefore
     * reintroduces exactly the account-enumeration leak the generic success
     * message exists to prevent: 422 means "this address has an account",
     * 200 means it does not.
     *
     * The cost is that a user who requests two links in quick succession is
     * told the second was sent when it was not. That is an acceptable trade —
     * the first email is on its way, and the endpoint's response is already
     * deliberately uninformative.
     *
     * Route-level rate limiting still protects the endpoint from abuse, and it
     * keys on email+IP rather than on account existence, so it cannot leak the
     * same way.
     */
    private function sendResetLink(string $broker, string $email): void
    {
        Password::broker($broker)->sendResetLink([
            'email' => strtolower(trim($email)),
        ]);

        // The status is intentionally discarded. The controller returns an
        // identical response for every outcome.
    }

    /**
     * Complete a customer password reset.
     *
     * @throws ValidationException
     */
    public function resetCustomerPassword(
        string $email,
        string $token,
        #[\SensitiveParameter] string $password,
    ): void {
        $this->resetPassword(self::BROKER_CUSTOMER, $email, $token, $password);
    }

    /**
     * @throws ValidationException
     */
    public function resetAdminPassword(
        string $email,
        string $token,
        #[\SensitiveParameter] string $password,
    ): void {
        $this->resetPassword(self::BROKER_ADMIN, $email, $token, $password);
    }

    /**
     * @throws ValidationException
     */
    private function resetPassword(
        string $broker,
        string $email,
        string $token,
        #[\SensitiveParameter] string $password,
    ): void {
        $status = Password::broker($broker)->reset(
            [
                'email' => strtolower(trim($email)),
                'password' => $password,
                'password_confirmation' => $password,
                'token' => $token,
            ],
            function (CanResetPassword $account) use ($password): void {
                /** @var User|Admin $account */
                $account->forceFill([
                    'password' => $password,
                    'password_changed_at' => now(),
                    'remember_token' => \Illuminate\Support\Str::random(60),
                ]);

                // A reset is the recovery path after a suspected compromise.
                // Every existing session must die — sparing any would leave
                // an attacker logged in after the legitimate owner "fixed" it.
                $account->tokens()->delete();

                if ($account instanceof Admin) {
                    $account->must_change_password = false;
                }

                $account->save();

                event(new PasswordReset($account));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [$this->messageFor($status)],
            ]);
        }
    }

    private function messageFor(string $status): string
    {
        return match ($status) {
            Password::INVALID_TOKEN => 'This password reset link is invalid or has expired.',
            Password::INVALID_USER => 'This password reset link is invalid or has expired.',
            Password::RESET_THROTTLED => 'Please wait before retrying.',
            default => 'The password could not be reset. Please request a new link.',
        };
    }
}
