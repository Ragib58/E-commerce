<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TokenAbility;
use App\Events\AdminLoggedIn;
use App\Models\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Staff authentication.
 *
 * Separate from CustomerAuthService rather than parameterised over a guard:
 * the two flows genuinely differ (no self-registration, no email
 * verification requirement, forced password rotation, shorter token life,
 * failed attempts logged for review), and merging them would produce a
 * method riddled with `if ($isAdmin)` branches — precisely where
 * authorization bugs hide.
 */
final class AdminAuthService
{
    /**
     * Authenticate a staff member.
     *
     * @return array{admin: Admin, token: string, expires_at: string|null, must_change_password: bool}
     *
     * @throws ValidationException
     */
    public function login(string $email, #[\SensitiveParameter] string $password, ?string $ipAddress = null): array
    {
        /** @var Admin|null $admin */
        $admin = Admin::query()
            ->with(['roles.permissions', 'directPermissions'])
            ->where('email', strtolower(trim($email)))
            ->first();

        // Constant-time behaviour on a missing account, as for customers.
        $passwordMatches = $admin !== null
            ? Hash::check($password, $admin->password)
            : Hash::check($password, $this->dummyHash());

        if ($admin === null || ! $passwordMatches) {
            // Failed staff logins are logged: repeated failures against a
            // privileged account are worth alerting on, unlike customer
            // typos. The password is never logged.
            Log::warning('Failed admin login attempt.', [
                'email' => $email,
                'ip' => $ipAddress,
            ]);

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if (! $admin->canAuthenticate()) {
            Log::warning('Login attempt on a deactivated admin account.', [
                'admin_uuid' => $admin->uuid,
                'ip' => $ipAddress,
            ]);

            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated.'],
            ]);
        }

        // An admin with no roles holds no permissions and would see an empty
        // panel. Refusing the login states the cause plainly instead.
        if ($admin->roles->isEmpty()) {
            throw ValidationException::withMessages([
                'email' => ['This account has no assigned role. Contact your system administrator.'],
            ]);
        }

        $admin->recordLogin($ipAddress);

        AdminLoggedIn::dispatch($admin, $ipAddress);

        $token = $this->issueToken($admin);

        return [
            'admin' => $admin,
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'must_change_password' => $admin->must_change_password,
        ];
    }

    public function logout(Admin $admin): void
    {
        $token = $admin->currentAccessToken();

        if ($token !== null) {
            $token->delete();
        }
    }

    public function logoutEverywhere(Admin $admin): void
    {
        $admin->tokens()->delete();
    }

    /**
     * Change a staff password.
     *
     * Unlike the customer flow, every other token is revoked without sparing
     * the current one when the change was forced — a compromised staff account
     * should lose all sessions the moment the password rotates.
     *
     * @throws ValidationException
     */
    public function changePassword(
        Admin $admin,
        #[\SensitiveParameter] string $currentPassword,
        #[\SensitiveParameter] string $newPassword,
    ): void {
        if (! Hash::check($currentPassword, $admin->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        if (Hash::check($newPassword, $admin->password)) {
            throw ValidationException::withMessages([
                'password' => ['The new password must differ from the current password.'],
            ]);
        }

        $currentTokenId = $admin->currentAccessToken()?->getKey();

        DB::transaction(function () use ($admin, $newPassword, $currentTokenId): void {
            $admin->forceFill([
                'password' => $newPassword,
                'password_changed_at' => now(),
                // Clearing the flag is what lets a forced-rotation account
                // out of the change-password gate.
                'must_change_password' => false,
            ])->save();

            if (config('auth.security.revoke_tokens_on_password_change')) {
                $admin->tokens()
                    ->when($currentTokenId !== null, fn ($query) => $query->whereKeyNot($currentTokenId))
                    ->delete();
            }
        });

        Log::info('Admin password changed.', ['admin_uuid' => $admin->uuid]);
    }

    private function issueToken(Admin $admin): NewAccessToken
    {
        $ttl = (int) config('auth.security.admin_token_ttl_minutes');

        return $admin->createToken(
            'admin-access',
            [TokenAbility::AdminAccess->value],
            $ttl > 0 ? now()->addMinutes($ttl) : null,
        );
    }

    private function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('timing-equalisation-placeholder');
    }
}
