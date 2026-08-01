<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TokenAbility;
use App\Events\CustomerLoggedIn;
use App\Events\CustomerRegistered;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Customer registration, login, logout, and profile management.
 *
 * All authentication state changes for customers funnel through here, so the
 * rules that matter — token abilities, session revocation on password change,
 * inactive-account handling — are enforced in one place rather than repeated
 * across controllers.
 */
final class CustomerAuthService
{
    /**
     * Register a customer and issue a token.
     *
     * The token is deliberately issued before email verification, with the
     * narrow `customer:unverified` ability. A user who must log in before
     * seeing "please verify your email" has no way to understand why their
     * account does not work.
     *
     * @param  array{name: string, email: string, password: string, phone?: string|null}  $data
     * @return array{user: User, token: string, expires_at: string|null}
     */
    public function register(array $data, ?string $ipAddress = null): array
    {
        $user = DB::transaction(function () use ($data): User {
            return User::query()->create([
                'name' => $data['name'],
                'email' => strtolower(trim($data['email'])),
                'password' => $data['password'],
                'phone' => $data['phone'] ?? null,
                'is_active' => true,
            ]);
        });

        // Laravel's own Registered event triggers the verification mail via
        // the framework listener; our domain event carries business meaning.
        event(new Registered($user));
        CustomerRegistered::dispatch($user);

        $user->recordLogin($ipAddress);

        $token = $this->issueToken($user);

        return [
            'user' => $user,
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
        ];
    }

    /**
     * Authenticate a customer.
     *
     * @return array{user: User, token: string, expires_at: string|null}
     *
     * @throws ValidationException
     */
    public function login(string $email, #[\SensitiveParameter] string $password, ?string $ipAddress = null): array
    {
        /** @var User|null $user */
        $user = User::query()->where('email', strtolower(trim($email)))->first();

        // Hash::check runs even when no user was found, against a dummy hash,
        // so the response time does not reveal whether an address is
        // registered. Returning early on a missing user would make account
        // enumeration trivial by timing.
        $passwordMatches = $user !== null
            ? Hash::check($password, $user->password)
            : Hash::check($password, $this->dummyHash());

        if ($user === null || ! $passwordMatches) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if (! $user->canAuthenticate()) {
            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated. Please contact support.'],
            ]);
        }

        $user->recordLogin($ipAddress);

        CustomerLoggedIn::dispatch($user, $ipAddress);

        $token = $this->issueToken($user);

        return [
            'user' => $user,
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
        ];
    }

    /**
     * Revoke the token used for the current request only.
     *
     * Other devices stay signed in — logging out on a phone should not sign
     * the user out on their laptop.
     */
    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token !== null) {
            $token->delete();
        }
    }

    /**
     * Revoke every token for this customer, across all devices.
     */
    public function logoutEverywhere(User $user): void
    {
        $user->tokens()->delete();
    }

    /**
     * Change a customer's password after verifying the current one.
     *
     * @throws ValidationException
     */
    public function changePassword(
        User $user,
        #[\SensitiveParameter] string $currentPassword,
        #[\SensitiveParameter] string $newPassword,
    ): void {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        // Comparing against the stored hash rather than the plaintext input
        // also catches "changing" to the same password via a different casing
        // of the same string only if identical — which is the intent.
        if (Hash::check($newPassword, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The new password must differ from the current password.'],
            ]);
        }

        $currentTokenId = $user->currentAccessToken()?->getKey();

        DB::transaction(function () use ($user, $newPassword, $currentTokenId): void {
            $user->forceFill([
                'password' => $newPassword,
                'password_changed_at' => now(),
            ])->save();

            // A password change must invalidate sessions opened with the old
            // one — otherwise changing a password after a compromise does not
            // actually evict the attacker. The current token is spared so the
            // user is not logged out of the device they just used.
            if (config('auth.security.revoke_tokens_on_password_change')) {
                $user->tokens()
                    ->when($currentTokenId !== null, fn ($query) => $query->whereKeyNot($currentTokenId))
                    ->delete();
            }
        });
    }

    /**
     * Update profile fields. Email and password are handled separately —
     * both have security implications a profile form should not carry.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateProfile(User $user, array $data): User
    {
        $user->fill(array_filter(
            [
                'name' => $data['name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
            ],
            static fn (mixed $value): bool => $value !== null,
        ));

        $user->save();

        return $user->refresh();
    }

    /**
     * Issue a Sanctum token with the ability appropriate to the account's
     * verification state.
     */
    private function issueToken(User $user): NewAccessToken
    {
        $ability = $user->hasVerifiedEmail()
            ? TokenAbility::CustomerAccess
            : TokenAbility::CustomerUnverified;

        $ttl = (int) config('auth.security.customer_token_ttl_minutes');

        return $user->createToken(
            'customer-access',
            [$ability->value],
            $ttl > 0 ? now()->addMinutes($ttl) : null,
        );
    }

    /**
     * A real bcrypt hash used only to equalise timing on a missing account.
     * Generated once per process rather than per call, since bcrypt is
     * deliberately slow.
     */
    private function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('timing-equalisation-placeholder');
    }
}
