<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockReservation>
 */
final class StockReservationFactory extends Factory
{
    protected $model = StockReservation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'product_variant_id' => null,
            'quantity' => 1,
            'checkout_session_id' => null,
            'order_id' => null,
            'status' => StockReservation::STATUS_ACTIVE,
            'expires_at' => now()->addMinutes(15),
            'released_at' => null,
        ];
    }

    /**
     * A hold on a specific stockable.
     *
     * Takes the variant's `product_id` from the variant itself rather than
     * requiring the caller to pass both, so a fixture cannot pair a variant
     * with the wrong parent — which would make availability arithmetic subtract
     * against a product that never held the units.
     */
    public function forStockable(Product|ProductVariant $stockable, int $quantity = 1): self
    {
        if ($stockable instanceof ProductVariant) {
            return $this->state(fn (): array => [
                'product_id' => $stockable->product_id,
                'product_variant_id' => $stockable->getKey(),
                'quantity' => $quantity,
            ]);
        }

        return $this->state(fn (): array => [
            'product_id' => $stockable->getKey(),
            'product_variant_id' => null,
            'quantity' => $quantity,
        ]);
    }

    /**
     * A hold that has lapsed but has not yet been swept.
     *
     * The state that matters: availability must already ignore it, so stock is
     * not stranded in the gap between expiry and the cleanup job running.
     */
    public function expired(): self
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    public function released(): self
    {
        return $this->state(fn (): array => [
            'status' => StockReservation::STATUS_RELEASED,
            'released_at' => now(),
        ]);
    }

    public function committed(): self
    {
        return $this->state(fn (): array => [
            'status' => StockReservation::STATUS_COMMITTED,
            'released_at' => now(),
        ]);
    }
}
