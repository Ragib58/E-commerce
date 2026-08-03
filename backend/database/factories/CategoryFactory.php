<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
final class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title($this->faker->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(4)),
            'description' => $this->faker->sentence(12),
            'meta_title' => $name,
            'meta_description' => $this->faker->sentence(14),
            'status' => ProductStatus::Published,
            'sort_order' => 0,
            'parent_id' => null,
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

    /**
     * Nest this category beneath another.
     *
     * The model's saved hook derives `path` and `depth` from `parent_id`, so
     * nothing here needs to set them — and a factory that did would be able to
     * write a tree the application could never produce.
     */
    public function childOf(Category $parent): self
    {
        return $this->state(fn (): array => ['parent_id' => $parent->getKey()]);
    }
}
