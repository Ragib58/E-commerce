<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MenuLocation;
use App\Models\Menu;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Menu>
 */
final class MenuFactory extends Factory
{
    protected $model = Menu::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'location' => MenuLocation::Header,
            'is_active' => true,
        ];
    }

    public function location(MenuLocation $location): self
    {
        return $this->state(fn (): array => ['location' => $location]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
