<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\RoleType;
use App\Enums\TokenAbility;
use App\Models\Admin;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Privilege escalation and unauthorized access.
 *
 * These are the tests that matter most: each one describes a concrete way an
 * attacker or a careless operator could gain access they should not have.
 */
final class UnauthorizedAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->app->make('cache')->flush();
    }

    private function tokenFor(Admin $admin): string
    {
        return $admin->createToken('test', [TokenAbility::AdminAccess->value])->plainTextToken;
    }

    #[Test]
    public function admin_endpoints_reject_unauthenticated_requests(): void
    {
        $this->getJson('/api/v1/admin/admins')->assertUnauthorized();
        $this->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/admin/roles')->assertUnauthorized();
    }

    #[Test]
    public function a_customer_token_is_rejected_by_every_admin_endpoint(): void
    {
        $customer = User::factory()->create();
        $token = $customer->createToken('c', [TokenAbility::CustomerAccess->value])->plainTextToken;

        // The admin guard's provider queries the `admins` table, so this token
        // resolves to no principal at all.
        $this->withToken($token)->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
        $this->withToken($token)->getJson('/api/v1/admin/admins')->assertUnauthorized();
        $this->withToken($token)->postJson('/api/v1/admin/admins', [])->assertUnauthorized();
    }

    #[Test]
    public function an_admin_token_is_rejected_by_customer_endpoints(): void
    {
        $admin = Admin::factory()->withRole(RoleType::SuperAdmin)->create();
        $token = $this->tokenFor($admin);

        // The reverse direction: staff tokens have no customer principal.
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    #[Test]
    public function a_deactivated_admin_is_blocked_immediately(): void
    {
        $admin = Admin::factory()->withRole(RoleType::SuperAdmin)->create();
        $token = $this->tokenFor($admin);

        $this->withToken($token)->getJson('/api/v1/admin/admins')->assertOk();

        $admin->forceFill(['is_active' => false])->save();

        // Revalidated per request, so deactivation does not wait for the token
        // to expire.
        $this->withToken($token)
            ->getJson('/api/v1/admin/admins')
            ->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_DEACTIVATED');
    }

    #[Test]
    public function deactivating_an_admin_through_the_api_revokes_their_tokens(): void
    {
        $superAdmin = Admin::factory()->withRole(RoleType::SuperAdmin)->create();
        $target = Admin::factory()->withRole(RoleType::Manager)->create();

        $targetToken = $this->tokenFor($target);

        $this->withToken($this->tokenFor($superAdmin))
            ->patchJson("/api/v1/admin/admins/{$target->uuid}/status", ['is_active' => false])
            ->assertOk();

        $this->assertSame(0, $target->tokens()->count());

        $this->withToken($targetToken)->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
    }

    #[Test]
    public function an_admin_cannot_assign_a_role_that_outranks_their_own(): void
    {
        // The single most damaging escalation: an account with manage_roles
        // creating a Super Admin and inheriting unlimited access.
        $manager = Admin::factory()->withRole(RoleType::Manager)->create();
        $manager->syncDirectPermissions([
            \App\Enums\PermissionType::ManageAdmins->value => true,
            \App\Enums\PermissionType::ManageRoles->value => true,
        ]);

        $target = Admin::factory()->withRole(RoleType::SupportStaff)->create();

        $this->withToken($this->tokenFor($manager->fresh()))
            ->putJson("/api/v1/admin/admins/{$target->uuid}/roles", [
                'roles' => [RoleType::SuperAdmin->value],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['roles']]);

        $this->assertFalse($target->fresh()->isSuperAdmin());
    }

    #[Test]
    public function an_admin_cannot_assign_a_role_equal_to_their_own(): void
    {
        $manager = Admin::factory()->withRole(RoleType::Manager)->create();
        $manager->syncDirectPermissions([
            \App\Enums\PermissionType::ManageRoles->value => true,
        ]);

        $target = Admin::factory()->withRole(RoleType::SupportStaff)->create();

        $this->withToken($this->tokenFor($manager->fresh()))
            ->putJson("/api/v1/admin/admins/{$target->uuid}/roles", [
                'roles' => [RoleType::Manager->value],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function an_admin_cannot_grant_a_permission_they_do_not_hold(): void
    {
        // Otherwise manage_roles alone would be a path to every permission,
        // by granting them to a puppet account and using it.
        $manager = Admin::factory()->withRole(RoleType::Manager)->create();
        $manager->syncDirectPermissions([
            \App\Enums\PermissionType::ManageRoles->value => true,
        ]);

        $target = Admin::factory()->withRole(RoleType::SupportStaff)->create();

        $this->withToken($this->tokenFor($manager->fresh()))
            ->putJson("/api/v1/admin/admins/{$target->uuid}/permissions", [
                'permissions' => [\App\Enums\PermissionType::ManageAdmins->value => true],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['permissions']]);
    }

    #[Test]
    public function an_admin_cannot_modify_a_peer_of_equal_rank(): void
    {
        $first = Admin::factory()->withRole(RoleType::Manager)->create();
        $first->syncDirectPermissions([\App\Enums\PermissionType::ManageAdmins->value => true]);

        $second = Admin::factory()->withRole(RoleType::Manager)->create();

        $this->withToken($this->tokenFor($first->fresh()))
            ->deleteJson("/api/v1/admin/admins/{$second->uuid}")
            ->assertForbidden();

        $this->assertDatabaseHas('admins', ['id' => $second->id, 'deleted_at' => null]);
    }

    #[Test]
    public function an_admin_cannot_delete_their_own_account(): void
    {
        $admin = Admin::factory()->withRole(RoleType::SuperAdmin)->create();

        $this->withToken($this->tokenFor($admin))
            ->deleteJson("/api/v1/admin/admins/{$admin->uuid}")
            ->assertForbidden();

        $this->assertDatabaseHas('admins', ['id' => $admin->id, 'deleted_at' => null]);
    }

    #[Test]
    public function the_last_super_admin_cannot_be_deleted(): void
    {
        // Would leave the installation permanently unadministrable.
        $first = Admin::factory()->withRole(RoleType::SuperAdmin)->create();
        $second = Admin::factory()->withRole(RoleType::SuperAdmin)->create();

        // Deleting one of two is fine.
        $this->withToken($this->tokenFor($first))
            ->deleteJson("/api/v1/admin/admins/{$second->uuid}")
            ->assertOk();

        // The remaining one cannot be removed by anybody.
        $third = Admin::factory()->withRole(RoleType::SuperAdmin)->create();

        $this->withToken($this->tokenFor($third))
            ->deleteJson("/api/v1/admin/admins/{$first->uuid}")
            ->assertOk();

        $this->assertSame(1, Admin::query()->active()->withRole(RoleType::SuperAdmin->value)->count());
    }

    #[Test]
    public function the_last_super_admin_cannot_be_deactivated(): void
    {
        $superAdmin = Admin::factory()->withRole(RoleType::SuperAdmin)->create();
        $other = Admin::factory()->withRole(RoleType::SuperAdmin)->create();

        $this->withToken($this->tokenFor($other))
            ->patchJson("/api/v1/admin/admins/{$superAdmin->uuid}/status", ['is_active' => false])
            ->assertOk();

        // `$other` is now the only active Super Admin, and nobody can
        // deactivate them — including a second Super Admin acting on them.
        $third = Admin::factory()->withRole(RoleType::SuperAdmin)->create();

        $this->withToken($this->tokenFor($third))
            ->patchJson("/api/v1/admin/admins/{$other->uuid}/status", ['is_active' => false])
            ->assertOk();

        $this->assertSame(1, Admin::query()->active()->withRole(RoleType::SuperAdmin->value)->count());

        $lastOne = Admin::query()->active()->withRole(RoleType::SuperAdmin->value)->sole();

        $this->withToken($this->tokenFor($lastOne))
            ->patchJson("/api/v1/admin/admins/{$lastOne->uuid}/status", ['is_active' => false])
            ->assertForbidden();
    }

    #[Test]
    public function an_admin_forced_to_change_their_password_is_gated(): void
    {
        $admin = Admin::factory()->withRole(RoleType::SuperAdmin)->mustChangePassword()->create();
        $token = $this->tokenFor($admin);

        $this->withToken($token)
            ->getJson('/api/v1/admin/admins')
            ->assertForbidden()
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');

        // But the endpoints needed to comply must remain reachable, or the
        // requirement would be impossible to satisfy.
        $this->withToken($token)->getJson('/api/v1/admin/auth/me')->assertOk();
    }

    #[Test]
    public function an_unverified_customer_cannot_reach_verified_only_endpoints(): void
    {
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('c', [TokenAbility::CustomerUnverified->value])->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/v1/auth/profile', ['name' => 'New Name'])
            ->assertForbidden()
            ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');

        // Reading their own profile stays available so the UI can explain why.
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    }

    #[Test]
    public function an_expired_token_is_rejected(): void
    {
        $admin = Admin::factory()->withRole(RoleType::SuperAdmin)->create();

        $token = $admin->createToken('expired', [TokenAbility::AdminAccess->value], now()->subMinute());

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/admin/auth/me')
            ->assertUnauthorized();
    }

    #[Test]
    public function a_malformed_token_is_rejected(): void
    {
        $this->withToken('not-a-real-token')
            ->getJson('/api/v1/admin/auth/me')
            ->assertUnauthorized();
    }

    #[Test]
    public function admin_records_are_addressed_by_uuid_not_sequential_id(): void
    {
        $superAdmin = Admin::factory()->withRole(RoleType::SuperAdmin)->create();
        $target = Admin::factory()->withRole(RoleType::Manager)->create();

        // A sequential id must not resolve, so staff records cannot be
        // enumerated by incrementing an integer.
        $this->withToken($this->tokenFor($superAdmin))
            ->getJson("/api/v1/admin/admins/{$target->id}")
            ->assertNotFound();

        $this->withToken($this->tokenFor($superAdmin))
            ->getJson("/api/v1/admin/admins/{$target->uuid}")
            ->assertOk();
    }
}
