<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\RoleType;
use App\Enums\TokenAbility;
use App\Models\Admin;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Variant CRUD and the rules that keep a product's option matrix coherent.
 */
final class VariantManagementTest extends TestCase
{
    use RefreshDatabase;

    private Admin $superAdmin;

    private Product $product;

    private Attribute $size;

    private Attribute $colour;

    /** @var array<string, AttributeValue> */
    private array $values = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->app->make('cache')->flush();

        $this->superAdmin = Admin::factory()->withRole(RoleType::SuperAdmin)->create();
        $this->product = Product::factory()->variable()->create(['sku' => 'TEE-001', 'price' => 2_000]);

        $this->size = Attribute::factory()->size()->create();
        $this->colour = Attribute::factory()->colour()->create();

        foreach (['S', 'M', 'L'] as $index => $size) {
            $this->values["size:{$size}"] = AttributeValue::factory()
                ->forAttribute($this->size)
                ->value($size)
                ->create(['sort_order' => $index]);
        }

        foreach (['Red', 'Blue'] as $index => $colour) {
            $this->values["colour:{$colour}"] = AttributeValue::factory()
                ->forAttribute($this->colour)
                ->value($colour)
                ->create(['sort_order' => $index]);
        }
    }

    private function asSuperAdmin(): self
    {
        $token = $this->superAdmin->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken;

        return $this->withToken($token);
    }

    #[Test]
    public function a_variant_can_be_created_from_attribute_values(): void
    {
        $response = $this->asSuperAdmin()->postJson(
            "/api/v1/admin/products/{$this->product->id}/variants",
            [
                'attribute_value_ids' => [
                    $this->values['size:M']->id,
                    $this->values['colour:Red']->id,
                ],
                'stock' => 12,
            ],
        );

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            // The display name is derived from the values, not typed in.
            ->assertJsonPath('data.name', 'M / Red');

        $this->assertDatabaseCount('product_variants', 1);
    }

    #[Test]
    public function the_first_variant_becomes_the_default(): void
    {
        $this->asSuperAdmin()->postJson(
            "/api/v1/admin/products/{$this->product->id}/variants",
            ['attribute_value_ids' => [$this->values['size:S']->id]],
        )->assertCreated();

        // A variable product whose page pre-selects nothing shows no price and
        // no add-to-cart.
        $this->assertDatabaseHas('product_variants', [
            'product_id' => $this->product->id,
            'is_default' => true,
        ]);
    }

    #[Test]
    public function a_duplicate_combination_is_rejected(): void
    {
        $ids = [$this->values['size:M']->id, $this->values['colour:Red']->id];

        $this->asSuperAdmin()->postJson(
            "/api/v1/admin/products/{$this->product->id}/variants",
            ['attribute_value_ids' => $ids],
        )->assertCreated();

        // Two variants both meaning "M / Red" make the option picker ambiguous
        // — the storefront cannot tell which SKU a shopper chose.
        $this->asSuperAdmin()->postJson(
            "/api/v1/admin/products/{$this->product->id}/variants",
            ['attribute_value_ids' => $ids],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attribute_value_ids');

        $this->assertDatabaseCount('product_variants', 1);
    }

    #[Test]
    public function a_variant_cannot_have_two_values_of_the_same_attribute(): void
    {
        // A variant that is both Red and Blue is not a thing that can be picked
        // or shipped.
        $this->asSuperAdmin()->postJson(
            "/api/v1/admin/products/{$this->product->id}/variants",
            [
                'attribute_value_ids' => [
                    $this->values['colour:Red']->id,
                    $this->values['colour:Blue']->id,
                ],
            ],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attribute_value_ids');
    }

    #[Test]
    public function a_simple_product_cannot_have_variants(): void
    {
        $simple = Product::factory()->create();

        $this->asSuperAdmin()->postJson(
            "/api/v1/admin/products/{$simple->id}/variants",
            ['attribute_value_ids' => [$this->values['size:S']->id]],
        )->assertUnprocessable();
    }

    #[Test]
    public function the_variant_matrix_can_be_generated_in_one_request(): void
    {
        $response = $this->asSuperAdmin()->postJson(
            "/api/v1/admin/products/{$this->product->id}/variants/generate",
            [
                'attributes' => [
                    [$this->values['size:S']->id, $this->values['size:M']->id, $this->values['size:L']->id],
                    [$this->values['colour:Red']->id, $this->values['colour:Blue']->id],
                ],
                'defaults' => ['stock' => 10],
            ],
        );

        // 3 sizes x 2 colours.
        $response->assertCreated()->assertJsonCount(6, 'data');

        $this->assertDatabaseCount('product_variants', 6);

        // The parent's roll-up must equal the sum of its variants.
        $this->assertSame(60, $this->product->refresh()->stock);
    }

    #[Test]
    public function regenerating_the_matrix_skips_combinations_that_already_exist(): void
    {
        $attributes = [
            [$this->values['size:S']->id, $this->values['size:M']->id],
            [$this->values['colour:Red']->id],
        ];

        $this->asSuperAdmin()->postJson(
            "/api/v1/admin/products/{$this->product->id}/variants/generate",
            ['attributes' => $attributes],
        )->assertCreated();

        // Re-running after adding a colour must add only the new combinations,
        // not fail and not duplicate.
        $response = $this->asSuperAdmin()->postJson(
            "/api/v1/admin/products/{$this->product->id}/variants/generate",
            [
                'attributes' => [
                    [$this->values['size:S']->id, $this->values['size:M']->id],
                    [$this->values['colour:Red']->id, $this->values['colour:Blue']->id],
                ],
            ],
        );

        $response->assertCreated()->assertJsonCount(2, 'data');

        $this->assertDatabaseCount('product_variants', 4);
    }

    #[Test]
    public function a_variant_price_falls_back_to_the_product_price(): void
    {
        $variant = ProductVariant::factory()->forProduct($this->product)->create(['price' => null]);

        $this->asSuperAdmin()
            ->getJson("/api/v1/admin/products/{$this->product->id}/variants")
            ->assertOk()
            // Inheritance is resolved server-side: a picker that showed a blank
            // price for non-overriding variants would be a bug appearing only
            // for a subset of products.
            ->assertJsonPath('data.0.pricing.effective_price', 2_000);

        $this->assertNull($variant->refresh()->price);
    }

    #[Test]
    public function a_variant_can_be_updated(): void
    {
        $variant = ProductVariant::factory()->forProduct($this->product)->create();

        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/variants/{$variant->uuid}", ['price' => 3_500])
            ->assertOk()
            ->assertJsonPath('data.pricing.effective_price', 3_500);

        $this->assertSame(3_500, $variant->refresh()->price);
    }

    #[Test]
    public function a_variant_can_be_deleted_when_it_is_not_the_last_one(): void
    {
        $first = ProductVariant::factory()->forProduct($this->product)->create();
        ProductVariant::factory()->forProduct($this->product)->create();

        $this->asSuperAdmin()
            ->deleteJson("/api/v1/admin/variants/{$first->uuid}")
            ->assertOk();

        $this->assertSoftDeleted('product_variants', ['id' => $first->id]);
    }

    #[Test]
    public function the_last_variant_of_a_variable_product_cannot_be_deleted(): void
    {
        $only = ProductVariant::factory()->forProduct($this->product)->create();

        // A variable product with no variants has nothing to sell.
        $this->asSuperAdmin()
            ->deleteJson("/api/v1/admin/variants/{$only->uuid}")
            ->assertUnprocessable();

        $this->assertDatabaseHas('product_variants', ['id' => $only->id, 'deleted_at' => null]);
    }

    #[Test]
    public function deleting_the_default_variant_promotes_another(): void
    {
        $default = ProductVariant::factory()->forProduct($this->product)->default()->create(['sort_order' => 0]);
        $other = ProductVariant::factory()->forProduct($this->product)->create(['sort_order' => 1]);

        $this->asSuperAdmin()
            ->deleteJson("/api/v1/admin/variants/{$default->uuid}")
            ->assertOk();

        // Never leave a product without a default.
        $this->assertTrue($other->refresh()->is_default);
    }
}
