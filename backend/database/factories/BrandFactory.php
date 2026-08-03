<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Brand>
 */
final class BrandFactory extends Factory
{
    protected $model = Brand::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title($this->faker->unique()->company());

        return [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(4)),
            'description' => $this->faker->sentence(12),
            'meta_title' => $name,
            'meta_description' => $this->faker->sentence(14),
            'status' => ProductStatus::Published,
            'sort_order' => 0,
        ];
    }

    public function published(): self
    {
        return $this->state(fn (): array => ['status' => ProductStatus::Published]);
    }

    public function draft(): self
    {
        return $this->state(fn (): array => ['status' => ProductStatus::Draft]);
    }
}
