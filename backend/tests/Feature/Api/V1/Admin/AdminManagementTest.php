<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\RoleType;
use App\Enums\TokenAbility;
use App\Models\Admin;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    private Admin $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->app->make('cache')->flush();

        $this->superAdmin = Admin::factory()->withRole(RoleType::SuperAdmin)->create();
    }

    private function asSuperAdmin(): self
    {
        $token = $this->superAdmin->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken;

        return $this->withToken($token);
    }

    #[Test]
    public function a_super_admin_can_create_an_administrator(): void
    {
        $response = $this->asSuperAdmin()->postJson('/api/v1/admin/admins', [
            'name' => 'New Manager',
            'email' => 'manager@example.com',
            'password' => 'Correct-Horse-9!',
            'password_confirmation' => 'Correct-Horse-9!',
            'roles' => [RoleType::Manager->value],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.admin.email', 'manager@example.com');

        $this->assertDatabaseHas('admins', ['email' => 'manager@example.com', 'is_active' => true]);

        $created = Admin::query()->where('email', 'manager@example.com')->sole();
        $this->assertTrue($created->hasRole(RoleType::Manager));
    }

    #[Test]
    public function omitting_a_password_generates_one_and_forces_rotation(): void
    {
        $response = $this->asSuperAdmin()->postJson('/api/v1/admin/admins', [
            'name' => 'Generated Password',
            'email' => 'generated@example.com',
            'roles' => [RoleType::SupportStaff->value],
        ]);

        $response
            ->assertCreated()
            ->assertJsonStructure(['data' => ['generated_password', 'password_notice']]);

        // The generated password has necessarily been displayed, so it must
        // not remain valid as a long-term credential.
        $created = Admin::query()->where('email', 'generated@example.com')->sole();
        $this->assertTrue($created->must_change_password);
    }

    #[Test]
    public function creating_an_administrator_requires_at_least_one_role(): void
    {
        $this->asSuperAdmin()->postJson('/api/v1/admin/admins', [
            'name' => 'No Role',
            'email' => 'norole@example.com',
            'roles' => [],
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['roles']]);
    }

    #[Test]
    public function an_administrator_email_must_be_unique_within_the_admins_table(): void
    {
        Admin::factory()->create(['email' => 'taken@example.com']);

        $this->asSuperAdmin()->postJson('/api/v1/admin/admins', [
            'name' => 'Duplicate',
            'email' => 'taken@example.com',
            'roles' => [RoleType::SupportStaff->value],
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    #[Test]
    public function an_admin_email_may_match_an_existing_customer_email(): void
    {
        // Different tables, different principals — a collision is legitimate.
        \App\Models\User::factory()->create(['email' => 'both@example.com']);

        $this->asSuperAdmin()->postJson('/api/v1/admin/admins', [
            'name' => 'Also A Customer',
            'email' => 'both@example.com',
            'roles' => [RoleType::SupportStaff->value],
        ])->assertCreated();
    }

    #[Test]
    public function a_super_admin_can_update_an_administrator(): void
    {
        $target = Admin::factory()->withRole(RoleType::Manager)->create();

        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/admins/{$target->uuid}", ['name' => 'Renamed Person'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Person');

        $this->assertSame('Renamed Person', $target->refresh()->name);
    }

    #[Test]
    public function a_super_admin_can_assign_roles(): void
    {
        $target = Admin::factory()->withRole(RoleType::SupportStaff)->create();

        $this->asSuperAdmin()
            ->putJson("/api/v1/admin/admins/{$target->uuid}/roles", [
                'roles' => [RoleType::ProductManager->value, RoleType::OrderManager->value],
            ])
            ->assertOk();

        $target->refresh();

        $this->assertTrue($target->hasRole(RoleType::ProductManager));
        $this->assertTrue($target->hasRole(RoleType::OrderManager));
        $this->assertFalse($target->hasRole(RoleType::SupportStaff));
    }

    #[Test]
    public function assigning_roles_replaces_rather_than_appends(): void
    {
        $target = Admin::factory()->withRole(RoleType::Manager)->create();

        $this->asSuperAdmin()
            ->putJson("/api/v1/admin/admins/{$target->uuid}/roles", [
                'roles' => [RoleType::SupportStaff->value],
            ])
            ->assertOk();

        // The panel submits the full desired set, so a removed role must
        // actually be removed.
        $this->assertSame([RoleType::SupportStaff->value], $target->refresh()->roles->pluck('name')->all());
    }

    #[Test]
    public function a_super_admin_can_grant_direct_permissions(): void
    {
        $target = Admin::factory()->withRole(RoleType::ProductManager)->create();

        $this->asSuperAdmin()
            ->putJson("/api/v1/admin/admins/{$target->uuid}/permissions", [
                'permissions' => [\App\Enums\PermissionType::RefundOrders->value => true],
            ])
            ->assertOk();

        $this->assertTrue($target->fresh()->hasPermission(\App\Enums\PermissionType::RefundOrders));
    }

    #[Test]
    public function a_super_admin_can_deactivate_and_reactivate_an_administrator(): void
    {
        $target = Admin::factory()->withRole(RoleType::Manager)->create();

        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/admins/{$target->uuid}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($target->refresh()->is_active);

        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/admins/{$target->uuid}/status", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertTrue($target->refresh()->is_active);
    }

    #[Test]
    public function a_super_admin_can_delete_an_administrator(): void
    {
        $target = Admin::factory()->withRole(RoleType::Manager)->create();

        $this->asSuperAdmin()
            ->deleteJson("/api/v1/admin/admins/{$target->uuid}")
            ->assertOk();

        // Soft deleted, so the account's past actions stay attributable.
        $this->assertSoftDeleted('admins', ['id' => $target->id]);
    }

    #[Test]
    public function the_administrator_list_can_be_filtered(): void
    {
        Admin::factory()->withRole(RoleType::Manager)->create(['name' => 'Findable Person']);
        Admin::factory()->withRole(RoleType::SupportStaff)->create(['name' => 'Other Person']);

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/admins?search=Findable')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/admins?role=' . RoleType::SupportStaff->value)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function the_administrator_list_is_paginated(): void
    {
        Admin::factory()->withRole(RoleType::SupportStaff)->count(5)->create();

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/admins?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['meta' => ['pagination' => ['current_page', 'last_page', 'total']]]);
    }

    #[Test]
    public function roles_and_permissions_can_be_listed_for_the_panel(): void
    {
        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/roles')
            ->assertOk()
            ->assertJsonStructure(['data' => [['name', 'label', 'level', 'is_system']]]);

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/permissions')
            ->assertOk()
            ->assertJsonStructure(['meta' => ['groups']]);
    }

    #[Test]
    public function the_role_list_excludes_roles_the_caller_cannot_assign(): void
    {
        $manager = Admin::factory()->withRole(RoleType::Manager)->create();
        $manager->syncDirectPermissions([\App\Enums\PermissionType::ManageRoles->value => true]);

        $token = $manager->fresh()->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken;

        $names = $this->withToken($token)->getJson('/api/v1/admin/roles')->json('data.*.name');

        // The UI must not offer an option the API would reject.
        $this->assertNotContains(RoleType::SuperAdmin->value, $names);
        $this->assertNotContains(RoleType::Admin->value, $names);
        $this->assertContains(RoleType::SupportStaff->value, $names);
    }
}
