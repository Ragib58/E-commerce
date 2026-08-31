<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ShippingMethod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ShippingMethod>
 */
final class ShippingMethodFactory extends Factory
{
    protected $model = ShippingMethod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->randomElement(['Standard', 'Express', 'Economy', 'Courier']);

        return [
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'code' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => $this->faker->sentence(8),

            // Minor units, as stored everywhere else.
            'rate' => $this->faker->numberBetween(0, 2_000),
            'free_above' => null,

            'min_days' => 2,
            'max_days' => 5,

            // Null means unconstrained. An empty array would read as "no
            // countries" to a careless implementation, so the default is the
            // one that cannot be misread.
            'countries' => null,
            'min_subtotal' => null,
            'max_subtotal' => null,

            'is_active' => true,
            'requires_address' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * A method that costs nothing.
     */
    public function free(): self
    {
        return $this->state(fn (): array => ['rate' => 0]);
    }

    /**
     * A flat rate, for tests that assert an exact shipping total.
     */
    public function rate(int $minorUnits): self
    {
        return $this->state(fn (): array => ['rate' => $minorUnits]);
    }

    /**
     * Free once the subtotal reaches a threshold.
     */
    public function freeAbove(int $threshold): self
    {
        return $this->state(fn (): array => ['free_above' => $threshold]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * Restricted to a set of ISO country codes.
     *
     * @param  array<int, string>  $codes
     */
    public function forCountries(array $codes): self
    {
        return $this->state(fn (): array => [
            'countries' => array_map(strtoupper(...), $codes),
        ]);
    }
}
