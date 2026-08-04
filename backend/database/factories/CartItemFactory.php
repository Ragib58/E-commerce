<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartItem>
 */
final class CartItemFactory extends Factory
{
    protected $model = CartItem::class;

    /**
     * Note the absence of any price field — there is none on the table. See
     * the cart_items migration for why.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'product_id' => Product::factory(),
            'product_variant_id' => null,
            'quantity' => 1,
            'options' => null,
        ];
    }

    public function forProduct(Product $product): self
    {
        return $this->state(fn (): array => ['product_id' => $product->getKey()]);
    }

    public function forVariant(ProductVariant $variant): self
    {
        return $this->state(fn (): array => [
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->getKey(),
        ]);
    }

    public function quantity(int $quantity): self
    {
        return $this->state(fn (): array => ['quantity' => $quantity]);
    }
}
