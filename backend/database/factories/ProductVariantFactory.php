<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductVariant>
 */
final class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'product_id' => Product::factory()->variable(),
            'sku' => strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(6)),
            'name' => null,

            // Null inherits the product's price — the common case, and the one
            // worth exercising by default.
            'price' => null,
            'discount_price' => null,
            'cost_price' => null,

            'stock' => 50,
            'low_stock_threshold' => 5,
            'allow_backorder' => false,
            'weight' => null,
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 0,
        ];
    }

    public function forProduct(Product $product): self
    {
        return $this->state(fn (): array => ['product_id' => $product->getKey()]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function default(): self
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }

    public function outOfStock(): self
    {
        return $this->state(fn (): array => ['stock' => 0]);
    }

    public function lowStock(int $stock = 2): self
    {
        return $this->state(fn (): array => ['stock' => $stock, 'low_stock_threshold' => 5]);
    }

    /**
     * Override the inherited price.
     */
    public function pricedAt(int $price): self
    {
        return $this->state(fn (): array => ['price' => $price]);
    }
}
