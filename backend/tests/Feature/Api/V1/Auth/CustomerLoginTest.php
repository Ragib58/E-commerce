<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Auth;

use App\Enums\TokenAbility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CustomerLoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct-Horse-9!';

    protected function setUp(): void
    {
        parent::setUp();

        // Limiter state persists across tests in the same process; clearing it
        // stops an earlier test's failed attempts from throttling a later one.
        RateLimiter::clear('');
        $this->app->make('cache')->flush();
    }

    private function customer(array $attributes = []): User
    {
        return User::factory()->withPassword(self::PASSWORD)->create($attributes);
    }

    #[Test]
    public function a_verified_customer_can_log_in(): void
    {
        $user = $this->customer();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->uuid)
            ->assertJsonStructure(['data' => ['user', 'token', 'token_type', 'expires_at']]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
        ]);
    }

    #[Test]
    public function a_verified_customer_receives_a_full_access_token(): void
    {
        $user = $this->customer();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $token = $user->tokens()->sole();

        $this->assertContains(TokenAbility::CustomerAccess->value, $token->abilities);
    }

    #[Test]
    public function an_unverified_customer_receives_a_restricted_token(): void
    {
        $user = User::factory()->unverified()->withPassword(self::PASSWORD)->create();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $token = $user->tokens()->sole();

        // The narrow ability is what stops an unverified account reaching
        // endpoints that require a confirmed address.
        $this->assertContains(TokenAbility::CustomerUnverified->value, $token->abilities);
        $this->assertNotContains(TokenAbility::CustomerAccess->value, $token->abilities);
    }

    #[Test]
    public function an_incorrect_password_is_rejected(): void
    {
        $user = $this->customer();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Wrong-Password-1!',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['email']]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[Test]
    public function an_unknown_email_returns_the_same_error_as_a_wrong_password(): void
    {
        $user = $this->customer();

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Wrong-Password-1!',
        ]);

        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'Wrong-Password-1!',
        ]);

        // Identical responses: a distinguishable error would let an attacker
        // enumerate which addresses have accounts.
        $this->assertSame($wrongPassword->status(), $unknownEmail->status());
        $this->assertSame(
            $wrongPassword->json('errors.email'),
            $unknownEmail->json('errors.email'),
        );
    }

    #[Test]
    public function a_deactivated_customer_cannot_log_in(): void
    {
        $user = User::factory()->inactive()->withPassword(self::PASSWORD)->create();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(422);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[Test]
    public function login_is_case_insensitive_on_the_email(): void
    {
        $user = $this->customer(['email' => 'ada@example.com']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ADA@Example.com',
            'password' => self::PASSWORD,
        ])->assertOk();
    }

    #[Test]
    public function login_records_the_last_login_timestamp(): void
    {
        $user = $this->customer();

        $this->assertNull($user->last_login_at);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->assertNotNull($user->refresh()->last_login_at);
    }

    #[Test]
    public function repeated_failures_are_rate_limited(): void
    {
        $user = $this->customer();

        $limit = (int) config('api.rate_limits.auth');

        for ($attempt = 0; $attempt < $limit; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'Wrong-Password-1!',
            ])->assertStatus(422);
        }

        // The next attempt must be throttled rather than merely rejected,
        // which is what makes credential stuffing impractical.
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Wrong-Password-1!',
        ])
            ->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMITED');
    }

    #[Test]
    public function a_customer_token_cannot_access_admin_endpoints(): void
    {
        $user = $this->customer();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->json('data.token');

        // The admin guard resolves against a different table, so this token
        // has no principal there at all.
        $this->withToken($token)
            ->getJson('/api/v1/admin/auth/me')
            ->assertUnauthorized();

        $this->withToken($token)
            ->getJson('/api/v1/admin/admins')
            ->assertUnauthorized();
    }
}
