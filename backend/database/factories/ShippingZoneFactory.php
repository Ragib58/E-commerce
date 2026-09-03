<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ShippingZone;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ShippingZone>
 */
final class ShippingZoneFactory extends Factory
{
    protected $model = ShippingZone::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->city();

        return [
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'code' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => null,
            'countries' => ['BD'],
            'states' => null,
            'cities' => null,
            'postcodes' => null,
            'priority' => 0,
            'is_fallback' => false,
            'is_active' => true,
        ];
    }

    /**
     * Matches by city name — the "Inside Dhaka" shape from the brief.
     *
     * @param  array<int, string>  $cities
     */
    public function forCities(array $cities): self
    {
        return $this->state(fn (): array => [
            'countries' => null,
            'cities' => $cities,
            'priority' => 10,
        ]);
    }

    /**
     * The catch-all zone a resolution falls back to.
     */
    public function fallback(): self
    {
        return $this->state(fn (): array => [
            'is_fallback' => true,
            'countries' => null,
            'states' => null,
            'cities' => null,
            'postcodes' => null,
            'priority' => 0,
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function priority(int $priority): self
    {
        return $this->state(fn (): array => ['priority' => $priority]);
    }
}
