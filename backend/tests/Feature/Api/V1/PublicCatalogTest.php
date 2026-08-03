<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The public storefront catalog.
 *
 * The assertions that matter most here are the negative ones: that a draft is
 * unreachable, and that margin and exact stock never appear on an
 * unauthenticated response.
 */
final class PublicCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();
    }

    #[Test]
    public function the_product_listing_returns_only_published_products(): void
    {
        Product::factory()->published()->create(['name' => 'Visible Product']);
        Product::factory()->draft()->create(['name' => 'Hidden Draft']);
        Product::factory()->archived()->create(['name' => 'Archived Product']);

        $response = $this->getJson('/api/v1/products')->assertOk();

        $names = array_column($response->json('data'), 'name');

        $this->assertContains('Visible Product', $names);
        $this->assertNotContains('Hidden Draft', $names);
        $this->assertNotContains('Archived Product', $names);
    }

    #[Test]
    public function a_draft_product_is_not_reachable_by_slug(): void
    {
        $draft = Product::factory()->draft()->create(['slug' => 'secret-launch']);

        // Indistinguishable from a slug that never existed. A different
        // response for "exists but unpublished" would let anyone enumerate the
        // unreleased catalog.
        $this->getJson("/api/v1/products/{$draft->slug}")
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function the_public_api_never_exposes_cost_price_or_exact_stock(): void
    {
        $product = Product::factory()->published()->create([
            'slug' => 'public-product',
            'cost_price' => 1_200,
            'stock' => 7,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->slug}")->assertOk();

        // Publishing cost price gives away the margin on every product;
        // publishing exact stock lets a competitor meter sales precisely.
        $response
            ->assertJsonMissingPath('data.pricing.cost_price')
            ->assertJsonMissingPath('data.inventory.stock');

        // The shopper-facing signal is still present.
        $response->assertJsonPath('data.inventory.in_stock', true);
    }

    #[Test]
    public function a_category_listing_includes_products_from_subcategories(): void
    {
        $parent = Category::factory()->create(['name' => 'Clothing', 'slug' => 'clothing']);
        $child = Category::factory()->childOf($parent)->create(['name' => 'Shirts']);

        Product::factory()->published()->inCategory($child)->create(['name' => 'Oxford Shirt']);

        // Clicking "Clothing" must show the shirts filed beneath it, not an
        // empty page.
        $response = $this->getJson("/api/v1/categories/{$parent->slug}")->assertOk();

        $this->assertContains('Oxford Shirt', array_column($response->json('data'), 'name'));
    }

    #[Test]
    public function a_category_page_returns_breadcrumbs(): void
    {
        $root = Category::factory()->create(['name' => 'Root']);
        $mid = Category::factory()->childOf($root)->create(['name' => 'Middle']);
        $leaf = Category::factory()->childOf($mid)->create(['name' => 'Leaf', 'slug' => 'leaf']);

        $response = $this->getJson("/api/v1/categories/{$leaf->slug}")->assertOk();

        $this->assertSame(
            ['Root', 'Middle', 'Leaf'],
            array_column($response->json('meta.breadcrumbs'), 'name'),
        );
    }

    #[Test]
    public function products_can_be_filtered_by_price_range(): void
    {
        Product::factory()->published()->create(['name' => 'Cheap', 'price' => 500]);
        Product::factory()->published()->create(['name' => 'Mid', 'price' => 5_000]);
        Product::factory()->published()->create(['name' => 'Expensive', 'price' => 50_000]);

        $response = $this->getJson('/api/v1/products?min_price=1000&max_price=10000')->assertOk();

        $names = array_column($response->json('data'), 'name');

        $this->assertSame(['Mid'], $names);
    }

    #[Test]
    public function products_can_be_searched(): void
    {
        Product::factory()->published()->create(['name' => 'Cashmere Sweater']);
        Product::factory()->published()->create(['name' => 'Leather Boots']);

        $response = $this->getJson('/api/v1/products?search=Cashmere')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Cashmere Sweater', $response->json('data.0.name'));
    }

    #[Test]
    public function products_can_be_sorted_by_price(): void
    {
        Product::factory()->published()->create(['name' => 'B', 'price' => 2_000]);
        Product::factory()->published()->create(['name' => 'A', 'price' => 1_000]);
        Product::factory()->published()->create(['name' => 'C', 'price' => 3_000]);

        $response = $this->getJson('/api/v1/products?sort=price_asc')->assertOk();

        $this->assertSame(['A', 'B', 'C'], array_column($response->json('data'), 'name'));
    }

    #[Test]
    public function an_unknown_sort_falls_back_to_the_default_rather_than_failing(): void
    {
        Product::factory()->published()->count(2)->create();

        // The allowlist means an unrecognised key cannot reach the ORDER BY.
        $this->getJson('/api/v1/products?sort=price); DROP TABLE products;--')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertDatabaseCount('products', 2);
    }

    #[Test]
    public function the_listing_is_paginated_and_capped(): void
    {
        Product::factory()->published()->count(30)->create();

        $this->getJson('/api/v1/products?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.pagination.total', 30);

        // A client must not be able to request the entire catalog at once.
        $response = $this->getJson('/api/v1/products?per_page=100000')->assertOk();

        $this->assertLessThanOrEqual(
            (int) config('catalog.listing.max_per_page'),
            $response->json('meta.pagination.per_page'),
        );
    }

    #[Test]
    public function a_variable_products_variants_are_returned_with_their_options(): void
    {
        $product = Product::factory()->variable()->published()->create(['slug' => 'tee']);

        $attribute = \App\Models\Attribute::factory()->size()->create();
        $value = \App\Models\AttributeValue::factory()->forAttribute($attribute)->value('M')->create();

        $variant = ProductVariant::factory()->forProduct($product)->create(['stock' => 5]);
        $variant->attributeValues()->attach($value->id);

        $response = $this->getJson("/api/v1/products/{$product->slug}")->assertOk();

        $response
            ->assertJsonPath('data.variants.0.options.0.attribute', 'size')
            ->assertJsonPath('data.variants.0.options.0.value', 'M');
    }

    #[Test]
    public function a_variant_inheriting_its_price_resolves_it_without_a_lazy_load(): void
    {
        // A variant with no price of its own inherits the product's, which
        // means serialising it reaches for the parent relation. With strict
        // mode on — as it is outside production — an unloaded relation is a
        // LazyLoadingViolationException, so a missed eager-load here is a 500
        // on the product page rather than a silent N+1.
        $product = Product::factory()->variable()->published()->create([
            'slug' => 'inherits-price',
            'price' => 4_200,
        ]);

        ProductVariant::factory()->forProduct($product)->create(['price' => null]);

        $this->getJson("/api/v1/products/{$product->slug}")
            ->assertOk()
            ->assertJsonPath('data.variants.0.pricing.effective_price', 4_200);
    }

    #[Test]
    public function an_inactive_variant_is_hidden_from_the_storefront(): void
    {
        $product = Product::factory()->variable()->published()->create(['slug' => 'tee']);

        ProductVariant::factory()->forProduct($product)->create(['sku' => 'ACTIVE-1']);
        ProductVariant::factory()->forProduct($product)->inactive()->create(['sku' => 'INACTIVE-1']);

        $response = $this->getJson("/api/v1/products/{$product->slug}")->assertOk();

        $skus = array_column($response->json('data.variants'), 'sku');

        $this->assertContains('ACTIVE-1', $skus);
        $this->assertNotContains('INACTIVE-1', $skus);
    }

    #[Test]
    public function only_published_categories_and_brands_are_listed(): void
    {
        Category::factory()->published()->create(['name' => 'Live Category']);
        Category::factory()->draft()->create(['name' => 'Draft Category']);

        Brand::factory()->published()->create(['name' => 'Live Brand']);
        Brand::factory()->draft()->create(['name' => 'Draft Brand']);

        $categories = $this->getJson('/api/v1/categories')->assertOk()->json('data');
        $brands = $this->getJson('/api/v1/brands')->assertOk()->json('data');

        $this->assertContains('Live Category', array_column($categories, 'name'));
        $this->assertNotContains('Draft Category', array_column($categories, 'name'));

        $this->assertContains('Live Brand', array_column($brands, 'name'));
        $this->assertNotContains('Draft Brand', array_column($brands, 'name'));
    }

    #[Test]
    public function the_filters_endpoint_returns_everything_a_filter_rail_needs(): void
    {
        Brand::factory()->published()->create();
        $attribute = \App\Models\Attribute::factory()->colour()->create();
        \App\Models\AttributeValue::factory()->forAttribute($attribute)->value('Red', '#ff0000')->create();

        Product::factory()->published()->create(['price' => 1_000]);
        Product::factory()->published()->create(['price' => 9_000]);

        $this->getJson('/api/v1/catalog/filters')
            ->assertOk()
            ->assertJsonPath('data.price_range.min', 1_000)
            ->assertJsonPath('data.price_range.max', 9_000)
            ->assertJsonPath('data.attributes.0.slug', 'colour')
            ->assertJsonPath('data.attributes.0.values.0.colour_code', '#ff0000');
    }

    #[Test]
    public function a_merchandising_rail_returns_only_its_flagged_products(): void
    {
        Product::factory()->published()->featured()->create(['name' => 'Featured One']);
        Product::factory()->published()->create(['name' => 'Ordinary']);

        $response = $this->getJson('/api/v1/catalog/rails/featured')->assertOk();

        $names = array_column($response->json('data'), 'name');

        $this->assertContains('Featured One', $names);
        $this->assertNotContains('Ordinary', $names);
    }
}
