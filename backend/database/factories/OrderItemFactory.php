<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrderItem>
 */
final class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = $this->faker->numberBetween(1, 3);
        $unitPrice = $this->faker->numberBetween(500, 20_000);

        return [
            'order_id' => Order::factory(),
            'product_id' => null,
            'product_variant_id' => null,

            // The snapshot columns an invoice actually renders.
            'product_name' => Str::title($this->faker->words(3, true)),
            'product_sku' => strtoupper(Str::random(8)),
            'variant_name' => null,
            'product_type' => 'simple',
            'variant_options' => null,
            'options' => null,
            'thumbnail_url' => null,

            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'list_price' => null,
            'discount_total' => 0,
            'tax_total' => 0,
            // The identity OrderItem::totalsReconcile() asserts.
            'line_total' => $unitPrice * $quantity,

            'is_taxable' => true,
            'stock_was_reduced' => true,
            'refunded_quantity' => 0,
        ];
    }

    /**
     * A line snapshotted from a real catalog product.
     *
     * Copies the fields exactly as OrderService does at placement, so a test
     * about restocking or re-ordering has a line whose `product_id` resolves.
     */
    public function forProduct(Product $product, int $quantity = 1): self
    {
        return $this->state(fn (): array => [
            'product_id' => $product->getKey(),
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'product_type' => $product->type->value,
            'quantity' => $quantity,
            'unit_price' => (int) $product->effective_price,
            'line_total' => (int) $product->effective_price * $quantity,
            'is_taxable' => (bool) $product->is_taxable,
        ]);
    }

    /**
     * A line that never took stock — a digital product, or a backordered one.
     */
    public function withoutStockReduction(): self
    {
        return $this->state(fn (): array => ['stock_was_reduced' => false]);
    }
}
