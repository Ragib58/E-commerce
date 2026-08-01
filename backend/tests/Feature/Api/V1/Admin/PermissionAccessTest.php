<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\PermissionType;
use App\Enums\RoleType;
use App\Enums\TokenAbility;
use App\Models\Admin;
use App\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Permission resolution: role grants, direct grants, direct revokes, and the
 * cache invalidation that makes a revocation take effect immediately.
 */
final class PermissionAccessTest extends TestCase
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
    public function permissions_are_inherited_from_an_assigned_role(): void
    {
        $admin = Admin::factory()->withRole(RoleType::ProductManager)->create();

        $this->assertTrue($admin->hasPermission(PermissionType::CreateProducts));
    }

    #[Test]
    public function a_direct_grant_adds_a_permission_beyond_the_role(): void
    {
        $admin = Admin::factory()->withRole(RoleType::ProductManager)->create();

        $this->assertFalse($admin->hasPermission(PermissionType::RefundOrders));

        // Lets an exception be made for one person without inventing a
        // bespoke role for them.
        $admin->syncDirectPermissions([PermissionType::RefundOrders->value => true]);

        $this->assertTrue($admin->fresh()->hasPermission(PermissionType::RefundOrders));
    }

    #[Test]
    public function a_direct_revoke_overrides_a_role_grant(): void
    {
        $admin = Admin::factory()->withRole(RoleType::ProductManager)->create();

        $this->assertTrue($admin->hasPermission(PermissionType::DeleteProducts));

        $admin->syncDirectPermissions([PermissionType::DeleteProducts->value => false]);

        // Revokes are applied last, so they win over any role that grants the
        // same permission.
        $this->assertFalse($admin->fresh()->hasPermission(PermissionType::DeleteProducts));
    }

    #[Test]
    public function a_revoke_wins_even_when_another_role_grants_the_permission(): void
    {
        $admin = Admin::factory()
            ->withRole(RoleType::ProductManager)
            ->withRole(RoleType::Manager)
            ->create();

        $this->assertTrue($admin->hasPermission(PermissionType::ViewProducts));

        $admin->syncDirectPermissions([PermissionType::ViewProducts->value => false]);

        $this->assertFalse($admin->fresh()->hasPermission(PermissionType::ViewProducts));
    }

    #[Test]
    public function multiple_roles_union_their_permissions(): void
    {
        $admin = Admin::factory()
            ->withRole(RoleType::ProductManager)
            ->withRole(RoleType::OrderManager)
            ->create();

        $this->assertTrue($admin->hasPermission(PermissionType::CreateProducts));
        $this->assertTrue($admin->hasPermission(PermissionType::RefundOrders));
    }

    #[Test]
    public function changing_roles_invalidates_the_cached_permission_set(): void
    {
        $admin = Admin::factory()->withRole(RoleType::SupportStaff)->create();

        // Prime the cache.
        $this->assertFalse($admin->hasPermission(PermissionType::CreateProducts));

        $admin->syncRoles([RoleType::ProductManager->value]);

        // A stale cache here would keep serving the old answer — the precise
        // failure that makes permission caching dangerous if done carelessly.
        $this->assertTrue($admin->fresh()->hasPermission(PermissionType::CreateProducts));
    }

    #[Test]
    public function revoking_a_role_takes_effect_immediately_on_the_api(): void
    {
        $admin = Admin::factory()->withRole(RoleType::SuperAdmin)->create();
        $token = $this->tokenFor($admin);

        $this->withToken($token)->getJson('/api/v1/admin/admins')->assertOk();

        $admin->syncRoles([RoleType::SupportStaff->value]);

        // Support Staff hold neither view_admins nor manage_admins.
        $this->withToken($token)
            ->getJson('/api/v1/admin/admins')
            ->assertForbidden()
            ->assertJsonPath('code', 'INSUFFICIENT_PERMISSIONS');
    }

    #[Test]
    public function the_middleware_reports_which_permission_was_required(): void
    {
        $admin = Admin::factory()->withRole(RoleType::SupportStaff)->create();

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/admin/admins')
            ->assertForbidden()
            ->assertJsonStructure(['required_permissions']);
    }

    #[Test]
    public function has_any_permission_requires_only_one_match(): void
    {
        $admin = Admin::factory()->withRole(RoleType::SupportStaff)->create();

        $this->assertTrue($admin->hasAnyPermission([
            PermissionType::ManageAdmins,
            PermissionType::ViewOrders,
        ]));
    }

    #[Test]
    public function has_all_permissions_requires_every_match(): void
    {
        $admin = Admin::factory()->withRole(RoleType::SupportStaff)->create();

        $this->assertFalse($admin->hasAllPermissions([
            PermissionType::ViewOrders,
            PermissionType::ManageAdmins,
        ]));

        $this->assertTrue($admin->hasAllPermissions([
            PermissionType::ViewOrders,
            PermissionType::ViewUsers,
        ]));
    }

    #[Test]
    public function the_seeder_grants_every_role_its_declared_permissions(): void
    {
        foreach (RoleType::cases() as $roleType) {
            if ($roleType->hasImplicitAllAccess()) {
                continue;
            }

            $role = Role::query()->where('name', $roleType->value)->sole();
            $granted = $role->permissions->pluck('name')->all();

            foreach ($roleType->defaultPermissions() as $expected) {
                $this->assertContains(
                    $expected->value,
                    $granted,
                    "Role {$roleType->value} should grant {$expected->value}.",
                );
            }
        }
    }

    #[Test]
    public function super_admin_is_seeded_without_explicit_permission_rows(): void
    {
        $role = Role::query()->where('name', RoleType::SuperAdmin->value)->sole();

        // Access comes from the Gate::before bypass, not a stored list, so a
        // new permission is never missing from the top role.
        $this->assertSame(0, $role->permissions()->count());
    }

    #[Test]
    public function gate_abilities_are_registered_for_every_permission(): void
    {
        $admin = Admin::factory()->withRole(RoleType::ProductManager)->create();

        // Powers `@can(...)` in Blade and `$admin->can(...)` in controllers.
        $this->assertTrue($admin->can(PermissionType::CreateProducts->value));
        $this->assertFalse($admin->can(PermissionType::ManageAdmins->value));
    }
}
