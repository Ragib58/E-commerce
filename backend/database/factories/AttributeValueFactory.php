<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Attribute;
use App\Models\AttributeValue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AttributeValue>
 */
final class AttributeValueFactory extends Factory
{
    protected $model = AttributeValue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $value = Str::title($this->faker->unique()->word());

        return [
            'attribute_id' => Attribute::factory(),
            'value' => $value,
            'slug' => Str::slug($value) . '-' . Str::lower(Str::random(4)),
            'colour_code' => null,
            'sort_order' => 0,
        ];
    }

    public function forAttribute(Attribute $attribute): self
    {
        return $this->state(fn (): array => ['attribute_id' => $attribute->getKey()]);
    }

    public function value(string $value, ?string $colourCode = null): self
    {
        return $this->state(fn (): array => [
            'value' => $value,
            'slug' => Str::slug($value),
            'colour_code' => $colourCode,
        ]);
    }
}
