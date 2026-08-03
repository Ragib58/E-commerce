<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title($this->faker->unique()->words(3, true));

        // Minor units, as stored. A float here would drift the moment a test
        // summed a few of them.
        $price = $this->faker->numberBetween(500, 250_000);

        return [
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(4)),
            'sku' => strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(6)),
            'short_description' => $this->faker->sentence(10),
            'description' => $this->faker->paragraphs(3, true),
            'category_id' => null,
            'brand_id' => null,
            'type' => ProductType::Simple,
            'price' => $price,
            'discount_price' => null,
            'cost_price' => (int) round($price * 0.6),
            'tax_rate' => null,
            'is_taxable' => true,
            'stock' => 100,
            'low_stock_threshold' => 5,
            'allow_backorder' => false,
            'weight' => $this->faker->numberBetween(100, 5000),
            'length' => $this->faker->numberBetween(10, 500),
            'width' => $this->faker->numberBetween(10, 500),
            'height' => $this->faker->numberBetween(10, 500),
            'status' => ProductStatus::Published,
            'is_featured' => false,
            'is_new_arrival' => false,
            'is_best_seller' => false,
            'meta_title' => $name,
            'meta_description' => $this->faker->sentence(14),
            'published_at' => now(),
        ];
    }

    public function published(): self
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function draft(): self
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::Draft,
            'published_at' => null,
        ]);
    }

    public function archived(): self
    {
        return $this->state(fn (): array => ['status' => ProductStatus::Archived]);
    }

    /**
     * A variable product.
     *
     * Stock is zeroed: a variable product's own column is a roll-up of its
     * variants, so seeding a figure here would contradict the sum from the
     * moment the first variant is attached.
     */
    public function variable(): self
    {
        return $this->state(fn (): array => [
            'type' => ProductType::Variable,
            'stock' => 0,
        ]);
    }

    public function digital(): self
    {
        return $this->state(fn (): array => [
            'type' => ProductType::Digital,
            'weight' => null,
            'length' => null,
            'width' => null,
            'height' => null,
            'stock' => 0,
        ]);
    }

    public function outOfStock(): self
    {
        return $this->state(fn (): array => ['stock' => 0]);
    }

    /**
     * Stock at or below the reorder point, but not yet zero.
     */
    public function lowStock(int $stock = 2): self
    {
        return $this->state(fn (): array => [
            'stock' => $stock,
            'low_stock_threshold' => 5,
        ]);
    }

    public function featured(): self
    {
        return $this->state(fn (): array => ['is_featured' => true]);
    }

    public function onSale(int $discount = 100): self
    {
        return $this->state(fn (array $attributes): array => [
            'discount_price' => max(1, (int) $attributes['price'] - $discount),
        ]);
    }

    public function inCategory(Category $category): self
    {
        return $this->state(fn (): array => ['category_id' => $category->getKey()]);
    }

    public function forBrand(Brand $brand): self
    {
        return $this->state(fn (): array => ['brand_id' => $brand->getKey()]);
    }
}
