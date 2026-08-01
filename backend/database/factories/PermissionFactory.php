<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PermissionType;
use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Permission>
 */
final class PermissionFactory extends Factory
{
    protected $model = Permission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'name' => Str::snake($name),
            'label' => Str::title($name),
            'group' => 'General',
            'description' => null,
        ];
    }

    public function ofType(PermissionType $type): self
    {
        return $this->state(fn (): array => [
            'name' => $type->value,
            'label' => $type->label(),
            'group' => $type->group(),
        ]);
    }
}
