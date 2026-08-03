<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\ProductStatus;
use App\Enums\RoleType;
use App\Enums\TokenAbility;
use App\Models\Admin;
use App\Models\Brand;
use App\Models\Product;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Brand CRUD through the admin API.
 */
final class BrandManagementTest extends TestCase
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
    public function an_administrator_can_create_a_brand(): void
    {
        $this->asSuperAdmin()
            ->postJson('/api/v1/admin/brands', [
                'name' => 'Northwind Traders',
                'description' => 'Established 1921.',
                'status' => ProductStatus::Published->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Northwind Traders')
            ->assertJsonPath('data.slug', 'northwind-traders');

        $this->assertDatabaseHas('brands', ['slug' => 'northwind-traders']);
    }

    #[Test]
    public function an_administrator_can_update_a_brand(): void
    {
        $brand = Brand::factory()->create(['name' => 'Old']);

        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/brands/{$brand->id}", ['name' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New');

        $this->assertSame('New', $brand->refresh()->name);
    }

    #[Test]
    public function a_brand_with_products_is_not_deleted_without_confirmation(): void
    {
        $brand = Brand::factory()->create();
        Product::factory()->forBrand($brand)->create();

        $this->asSuperAdmin()
            ->deleteJson("/api/v1/admin/brands/{$brand->id}")
            ->assertUnprocessable();

        $this->assertDatabaseHas('brands', ['id' => $brand->id, 'deleted_at' => null]);
    }

    #[Test]
    public function deleting_a_brand_with_cascade_keeps_its_products(): void
    {
        $brand = Brand::factory()->create();
        $product = Product::factory()->forBrand($brand)->create();

        $this->asSuperAdmin()
            ->deleteJson("/api/v1/admin/brands/{$brand->id}?cascade=1")
            ->assertOk();

        // Products outlive their brand: they are saleable inventory with order
        // history, and tidying a brand list must not delete them.
        $this->assertNull($product->refresh()->brand_id);
        $this->assertSoftDeleted('brands', ['id' => $brand->id]);
    }

    #[Test]
    public function brands_can_be_searched_and_paginated(): void
    {
        Brand::factory()->create(['name' => 'Alpha Industries']);
        Brand::factory()->count(5)->create();

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/brands?search=Alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Industries');

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/brands?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.total', 6);
    }

    #[Test]
    public function a_support_agent_cannot_create_a_brand(): void
    {
        $support = Admin::factory()->withRole(RoleType::SupportStaff)->create();
        $token = $support->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/admin/brands', ['name' => 'Forbidden'])
            ->assertForbidden();
    }
}
