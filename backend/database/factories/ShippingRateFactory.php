<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ShippingMethod;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingRate>
 */
final class ShippingRateFactory extends Factory
{
    protected $model = ShippingRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shipping_method_id' => ShippingMethod::factory(),
            'shipping_zone_id' => ShippingZone::factory(),
            'rate' => $this->faker->numberBetween(0, 2_000),
            'free_above' => null,
            'min_subtotal' => null,
            'max_subtotal' => null,
            'min_days' => null,
            'max_days' => null,
            'is_active' => true,
        ];
    }

    public function forMethod(ShippingMethod $method): self
    {
        return $this->state(fn (): array => ['shipping_method_id' => $method->getKey()]);
    }

    public function forZone(ShippingZone $zone): self
    {
        return $this->state(fn (): array => ['shipping_zone_id' => $zone->getKey()]);
    }

    public function rate(int $minorUnits): self
    {
        return $this->state(fn (): array => ['rate' => $minorUnits]);
    }

    public function freeAbove(int $threshold): self
    {
        return $this->state(fn (): array => ['free_above' => $threshold]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
