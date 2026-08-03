<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attribute>
 */
final class AttributeFactory extends Factory
{
    protected $model = Attribute::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title($this->faker->unique()->word());

        return [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(4)),
            'display_type' => 'button',
            'is_filterable' => true,
            'sort_order' => 0,
        ];
    }

    public function colour(): self
    {
        return $this->state(fn (): array => [
            'name' => 'Colour',
            'slug' => 'colour',
            'display_type' => 'swatch',
        ]);
    }

    public function size(): self
    {
        return $this->state(fn (): array => [
            'name' => 'Size',
            'slug' => 'size',
            'display_type' => 'button',
        ]);
    }
}
