<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Auth;

use App\Enums\RoleType;
use App\Enums\TokenAbility;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LogoutTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_authenticated_customer_can_log_out(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test', [TokenAbility::CustomerAccess->value]);

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[Test]
    public function logging_out_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();

        $phone = $user->createToken('phone', [TokenAbility::CustomerAccess->value]);
        $laptop = $user->createToken('laptop', [TokenAbility::CustomerAccess->value]);

        $this->withToken($phone->plainTextToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // Signing out on one device must not sign the user out everywhere.
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $phone->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $laptop->accessToken->id]);
    }

    #[Test]
    public function logout_all_revokes_every_token(): void
    {
        $user = User::factory()->create();

        $phone = $user->createToken('phone', [TokenAbility::CustomerAccess->value]);
        $user->createToken('laptop', [TokenAbility::CustomerAccess->value]);
        $user->createToken('tablet', [TokenAbility::CustomerAccess->value]);

        $this->withToken($phone->plainTextToken)
            ->postJson('/api/v1/auth/logout-all')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[Test]
    public function a_revoked_token_can_no_longer_authenticate(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test', [TokenAbility::CustomerAccess->value]);

        $this->withToken($token->plainTextToken)->postJson('/api/v1/auth/logout')->assertOk();

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    #[Test]
    public function logout_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    #[Test]
    public function an_admin_can_log_out(): void
    {
        $admin = Admin::factory()->withRole(RoleType::Admin)->create();
        $token = $admin->createToken('test', [TokenAbility::AdminAccess->value]);

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/admin/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[Test]
    public function an_unauthenticated_request_returns_the_error_envelope(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'code']);
    }
}
