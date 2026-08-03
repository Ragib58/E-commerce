<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StockMovementReason;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 *
 * For seeding history only. Production code must never create movements
 * directly — InventoryService writes the level and the ledger row together, and
 * a movement made here changes no stock level, so the two would disagree.
 */
final class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $before = $this->faker->numberBetween(10, 200);
        $quantity = $this->faker->numberBetween(1, 20);

        return [
            'product_id' => Product::factory(),
            'product_variant_id' => null,
            'type' => StockMovementType::Increase,
            'reason' => StockMovementReason::Restock,
            'quantity' => $quantity,
            'quantity_before' => $before,
            'quantity_after' => $before + $quantity,
            'admin_id' => null,
            'note' => null,
            'created_at' => now(),
        ];
    }

    public function decrease(int $quantity = 5): self
    {
        return $this->state(fn (array $attributes): array => [
            'type' => StockMovementType::Decrease,
            'reason' => StockMovementReason::Sale,
            'quantity' => -$quantity,
            'quantity_after' => $attributes['quantity_before'] - $quantity,
        ]);
    }

    public function shrinkage(): self
    {
        return $this->state(fn (array $attributes): array => [
            'type' => StockMovementType::Decrease,
            'reason' => StockMovementReason::Damage,
            'quantity' => -abs((int) $attributes['quantity']),
            'quantity_after' => $attributes['quantity_before'] - abs((int) $attributes['quantity']),
        ]);
    }
}
