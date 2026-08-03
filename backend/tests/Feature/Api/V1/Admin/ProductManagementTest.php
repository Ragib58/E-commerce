<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\RoleType;
use App\Enums\TokenAbility;
use App\Models\Admin;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Product CRUD through the admin API.
 */
final class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    private Admin $superAdmin;

    private Category $category;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->app->make('cache')->flush();

        $this->superAdmin = Admin::factory()->withRole(RoleType::SuperAdmin)->create();
        $this->category = Category::factory()->create();
        $this->brand = Brand::factory()->create();
    }

    private function asSuperAdmin(): self
    {
        $token = $this->superAdmin->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken;

        return $this->withToken($token);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Merino Wool Scarf',
            'sku' => 'SCARF-001',
            'short_description' => 'Warm and light.',
            'category_id' => $this->category->id,
            'brand_id' => $this->brand->id,
            'type' => ProductType::Simple->value,
            'price' => 4_999,
            'stock' => 25,
            'status' => ProductStatus::Published->value,
        ], $overrides);
    }

    #[Test]
    public function an_administrator_can_create_a_product(): void
    {
        $response = $this->asSuperAdmin()
            ->postJson('/api/v1/admin/products', $this->validPayload());

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Merino Wool Scarf')
            ->assertJsonPath('data.pricing.price', 4999);

        $this->assertDatabaseHas('products', ['sku' => 'SCARF-001', 'price' => 4999]);
    }

    #[Test]
    public function creating_a_product_records_its_opening_stock_as_a_movement(): void
    {
        $this->asSuperAdmin()
            ->postJson('/api/v1/admin/products', $this->validPayload(['stock' => 25]))
            ->assertCreated();

        $product = Product::query()->where('sku', 'SCARF-001')->sole();

        $this->assertSame(25, $product->stock);

        // The ledger must reconcile from zero. A product whose stock appeared
        // without a movement would begin its history with unexplained units.
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'reason' => 'initial_stock',
            'quantity' => 25,
            'quantity_before' => 0,
            'quantity_after' => 25,
        ]);
    }

    #[Test]
    public function a_product_slug_and_sku_are_generated_when_omitted(): void
    {
        $payload = $this->validPayload();
        unset($payload['sku']);

        $response = $this->asSuperAdmin()
            ->postJson('/api/v1/admin/products', $payload)
            ->assertCreated();

        $this->assertSame('merino-wool-scarf', $response->json('data.slug'));
        $this->assertNotEmpty($response->json('data.sku'));
    }

    #[Test]
    public function a_duplicate_sku_is_rejected(): void
    {
        Product::factory()->create(['sku' => 'SCARF-001']);

        $this->asSuperAdmin()
            ->postJson('/api/v1/admin/products', $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sku');
    }

    #[Test]
    public function a_discount_price_must_be_below_the_regular_price(): void
    {
        $this->asSuperAdmin()
            ->postJson('/api/v1/admin/products', $this->validPayload([
                'price' => 1_000,
                'discount_price' => 1_000,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('discount_price');
    }

    #[Test]
    public function a_patch_updating_only_the_discount_still_compares_against_the_stored_price(): void
    {
        $product = Product::factory()->create(['price' => 1_000]);

        // The create-time `lt:price` rule cannot fire here — the request has no
        // `price` field to compare against — so this is what proves the
        // stored-price comparison actually runs.
        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/products/{$product->id}", ['discount_price' => 2_000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('discount_price');
    }

    #[Test]
    public function an_administrator_can_update_a_product(): void
    {
        $product = Product::factory()->create(['name' => 'Old Name']);

        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/products/{$product->id}", ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $this->assertSame('New Name', $product->refresh()->name);
    }

    #[Test]
    public function renaming_a_product_does_not_change_its_slug(): void
    {
        $product = Product::factory()->create(['name' => 'Original', 'slug' => 'original']);

        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/products/{$product->id}", ['name' => 'Completely Different'])
            ->assertOk();

        // Reslugging on rename would break every inbound link and search result
        // pointing at the old URL.
        $this->assertSame('original', $product->refresh()->slug);
    }

    #[Test]
    public function editing_the_stock_field_records_a_movement(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/products/{$product->id}", ['stock' => 40])
            ->assertOk();

        $this->assertSame(40, $product->refresh()->stock);

        // Applied as an absolute set: the figure typed into the form is an
        // assertion about the shelf, not a delta.
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'reason' => 'manual_edit',
            'quantity_before' => 10,
            'quantity_after' => 40,
            'quantity' => 30,
        ]);
    }

    #[Test]
    public function a_product_is_soft_deleted_and_can_be_restored(): void
    {
        $product = Product::factory()->create();

        $this->asSuperAdmin()
            ->deleteJson("/api/v1/admin/products/{$product->id}")
            ->assertOk();

        // Soft delete only: the stock ledger and later the order history hold
        // foreign keys to this row.
        $this->assertSoftDeleted('products', ['id' => $product->id]);

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/products/{$product->id}/restore")
            ->assertOk();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
    }

    #[Test]
    public function the_product_list_can_be_searched_and_filtered(): void
    {
        Product::factory()->create(['name' => 'Alpha Widget', 'sku' => 'AAA-111']);
        Product::factory()->create(['name' => 'Beta Gadget', 'sku' => 'BBB-222']);
        Product::factory()->draft()->create(['name' => 'Draft Thing']);

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/products?search=Alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Widget');

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/products?status=draft')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Draft Thing');
    }

    #[Test]
    public function the_product_list_is_paginated(): void
    {
        Product::factory()->count(30)->create();

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/products?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.pagination.total', 30)
            ->assertJsonPath('meta.pagination.per_page', 10);
    }

    #[Test]
    public function a_bulk_action_publishes_many_products_at_once(): void
    {
        $products = Product::factory()->draft()->count(3)->create();

        $this->asSuperAdmin()
            ->postJson('/api/v1/admin/products/bulk', [
                // UUIDs, matching what the API exposes — the integer key is
                // never published, so a client could not send one.
                'ids' => $products->pluck('uuid')->all(),
                'action' => 'publish',
            ])
            ->assertOk()
            ->assertJsonPath('data.affected', 3);

        foreach ($products as $product) {
            $this->assertSame(ProductStatus::Published, $product->refresh()->status);
        }
    }

    #[Test]
    public function the_status_toggle_publishes_a_product(): void
    {
        $product = Product::factory()->draft()->create();

        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/products/{$product->id}/status", [
                'status' => ProductStatus::Published->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->assertNotNull($product->refresh()->published_at);
    }

    #[Test]
    public function an_image_can_be_uploaded_and_becomes_the_thumbnail(): void
    {
        Storage::fake('local');

        $product = Product::factory()->create();

        $response = $this->asSuperAdmin()->post(
            "/api/v1/admin/products/{$product->id}/media",
            ['image' => UploadedFile::fake()->image('front.jpg')],
            ['Accept' => 'application/json'],
        );

        $response
            ->assertCreated()
            // The first image becomes the thumbnail automatically: a product
            // with a gallery but no thumbnail renders a blank card.
            ->assertJsonPath('data.is_thumbnail', true);

        $this->assertDatabaseHas('product_media', [
            'product_id' => $product->id,
            'is_thumbnail' => true,
        ]);
    }

    #[Test]
    public function a_digital_product_cannot_be_given_a_shipping_weight(): void
    {
        $this->asSuperAdmin()
            ->postJson('/api/v1/admin/products', $this->validPayload([
                'type' => ProductType::Digital->value,
                'weight' => 500,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('weight');
    }

    #[Test]
    public function a_product_manager_can_create_but_a_support_agent_cannot(): void
    {
        $manager = Admin::factory()->withRole(RoleType::ProductManager)->create();
        $support = Admin::factory()->withRole(RoleType::SupportStaff)->create();

        $managerToken = $manager->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken;
        $supportToken = $support->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken;

        $this->withToken($managerToken)
            ->postJson('/api/v1/admin/products', $this->validPayload())
            ->assertCreated();

        $this->withToken($supportToken)
            ->postJson('/api/v1/admin/products', $this->validPayload(['sku' => 'OTHER-001']))
            ->assertForbidden();
    }

    #[Test]
    public function an_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/v1/admin/products', $this->validPayload())
            ->assertUnauthorized();
    }
}
