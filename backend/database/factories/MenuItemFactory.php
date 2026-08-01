<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Menu;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuItem>
 */
final class MenuItemFactory extends Factory
{
    protected $model = MenuItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'menu_id' => Menu::factory(),
            'parent_id' => null,
            'label' => $this->faker->words(2, true),
            'url' => '/' . $this->faker->slug(2),
            'icon' => null,
            'target' => '_self',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function childOf(MenuItem $parent): self
    {
        return $this->state(fn (): array => [
            'menu_id' => $parent->menu_id,
            'parent_id' => $parent->id,
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
