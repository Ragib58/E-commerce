<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\PermissionType;
use App\Enums\RoleType;
use App\Enums\TokenAbility;
use App\Models\Admin;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verifies that each seeded role grants the access it should, and none beyond.
 */
final class AdminRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->app->make('cache')->flush();
    }

    private function actingAsAdmin(RoleType $role): Admin
    {
        $admin = Admin::factory()->withRole($role)->create();

        $token = $admin->createToken('test', [TokenAbility::AdminAccess->value]);

        $this->withToken($token->plainTextToken);

        return $admin;
    }

    #[Test]
    public function a_super_admin_holds_every_permission(): void
    {
        $admin = $this->actingAsAdmin(RoleType::SuperAdmin);

        // Resolved as a bypass rather than a stored list, so a newly added
        // permission is available immediately without a re-seed.
        foreach (PermissionType::cases() as $permission) {
            $this->assertTrue(
                $admin->hasPermission($permission),
                "Super Admin should hold {$permission->value}.",
            );
        }
    }

    #[Test]
    public function a_super_admin_can_list_administrators(): void
    {
        $this->actingAsAdmin(RoleType::SuperAdmin);

        $this->getJson('/api/v1/admin/admins')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function an_admin_role_cannot_manage_administrators(): void
    {
        $admin = $this->actingAsAdmin(RoleType::Admin);

        // The deliberate exclusion: manage_admins and manage_roles are
        // escalation vectors, so the Admin role does not receive them.
        $this->assertFalse($admin->hasPermission(PermissionType::ManageAdmins));
        $this->assertFalse($admin->hasPermission(PermissionType::ManageRoles));

        $this->postJson('/api/v1/admin/admins', [
            'name' => 'New Person',
            'email' => 'new@example.com',
            'roles' => [RoleType::SupportStaff->value],
        ])->assertForbidden();
    }

    #[Test]
    public function an_admin_role_holds_every_non_privileged_permission(): void
    {
        $admin = $this->actingAsAdmin(RoleType::Admin);

        foreach (PermissionType::cases() as $permission) {
            $expected = ! $permission->isPrivileged();

            $this->assertSame(
                $expected,
                $admin->hasPermission($permission),
                "Admin role permission mismatch for {$permission->value}.",
            );
        }
    }

    #[Test]
    public function a_product_manager_can_manage_products_but_not_orders(): void
    {
        $admin = $this->actingAsAdmin(RoleType::ProductManager);

        $this->assertTrue($admin->hasPermission(PermissionType::CreateProducts));
        $this->assertTrue($admin->hasPermission(PermissionType::DeleteProducts));

        $this->assertFalse($admin->hasPermission(PermissionType::UpdateOrders));
        $this->assertFalse($admin->hasPermission(PermissionType::RefundOrders));
        $this->assertFalse($admin->hasPermission(PermissionType::ManageSettings));
    }

    #[Test]
    public function an_order_manager_can_refund_but_cannot_edit_the_catalog(): void
    {
        $admin = $this->actingAsAdmin(RoleType::OrderManager);

        $this->assertTrue($admin->hasPermission(PermissionType::RefundOrders));
        $this->assertTrue($admin->hasPermission(PermissionType::ViewProducts));

        $this->assertFalse($admin->hasPermission(PermissionType::CreateProducts));
        $this->assertFalse($admin->hasPermission(PermissionType::UpdateProducts));
    }

    #[Test]
    public function a_content_manager_can_manage_settings_but_not_orders(): void
    {
        $admin = $this->actingAsAdmin(RoleType::ContentManager);

        $this->assertTrue($admin->hasPermission(PermissionType::ManageSettings));
        $this->assertTrue($admin->hasPermission(PermissionType::ManageMenus));

        $this->assertFalse($admin->hasPermission(PermissionType::ViewOrders));
        $this->assertFalse($admin->hasPermission(PermissionType::CreateProducts));
    }

    #[Test]
    public function support_staff_have_read_mostly_access(): void
    {
        $admin = $this->actingAsAdmin(RoleType::SupportStaff);

        $this->assertTrue($admin->hasPermission(PermissionType::ViewOrders));
        $this->assertTrue($admin->hasPermission(PermissionType::ViewUsers));
        $this->assertTrue($admin->hasPermission(PermissionType::ManageSupportTickets));

        $this->assertFalse($admin->hasPermission(PermissionType::UpdateOrders));
        $this->assertFalse($admin->hasPermission(PermissionType::ManageUsers));
        $this->assertFalse($admin->hasPermission(PermissionType::RefundOrders));
    }

    #[Test]
    public function role_levels_establish_a_strict_hierarchy(): void
    {
        $superAdmin = Admin::factory()->withRole(RoleType::SuperAdmin)->create();
        $admin = Admin::factory()->withRole(RoleType::Admin)->create();
        $manager = Admin::factory()->withRole(RoleType::Manager)->create();
        $support = Admin::factory()->withRole(RoleType::SupportStaff)->create();

        $this->assertTrue($superAdmin->outranks($admin));
        $this->assertTrue($admin->outranks($manager));
        $this->assertTrue($manager->outranks($support));

        $this->assertFalse($support->outranks($manager));
        $this->assertFalse($admin->outranks($superAdmin));
    }

    #[Test]
    public function equal_rank_admins_do_not_outrank_each_other(): void
    {
        $first = Admin::factory()->withRole(RoleType::Manager)->create();
        $second = Admin::factory()->withRole(RoleType::Manager)->create();

        // Strict inequality prevents two peers deleting one another.
        $this->assertFalse($first->outranks($second));
        $this->assertFalse($second->outranks($first));
    }

    #[Test]
    public function an_admin_with_no_role_cannot_log_in(): void
    {
        $admin = Admin::factory()->withPassword('Correct-Horse-9!')->create();

        // Would otherwise authenticate into a panel with nothing in it.
        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => 'Correct-Horse-9!',
        ])->assertStatus(422);
    }
}
