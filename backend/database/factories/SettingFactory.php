<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SettingGroup;
use App\Enums\SettingType;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
final class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'general.' . $this->faker->unique()->word(),
            'value' => $this->faker->sentence(3),
            'type' => SettingType::String,
            'group' => SettingGroup::General,
            'label' => $this->faker->words(2, true),
            'description' => null,
            'is_public' => true,
            'is_locked' => false,
            'sort_order' => 0,
        ];
    }

    public function private(): self
    {
        return $this->state(fn (): array => ['is_public' => false]);
    }

    public function locked(): self
    {
        return $this->state(fn (): array => ['is_locked' => true]);
    }

    public function ofType(SettingType $type, mixed $value = null): self
    {
        return $this->state(fn (): array => [
            'type' => $type,
            'value' => $type->serialize($value),
        ]);
    }

    public function inGroup(SettingGroup $group): self
    {
        return $this->state(fn (): array => [
            'group' => $group,
            'key' => $group->value . '.' . $this->faker->unique()->word(),
        ]);
    }
}
