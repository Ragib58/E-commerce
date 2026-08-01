<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
final class RoleFactory extends Factory
{
    protected $model = Role::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = $this->faker->unique()->words(2, true);

        return [
            'name' => Str::snake($label),
            'label' => Str::title($label),
            'description' => $this->faker->sentence(),
            'level' => 40,
            'is_system' => false,
        ];
    }

    public function level(int $level): self
    {
        return $this->state(fn (): array => ['level' => $level]);
    }

    public function system(): self
    {
        return $this->state(fn (): array => ['is_system' => true]);
    }

    /**
     * @param  array<int, PermissionType|string>  $permissions
     */
    public function withPermissions(array $permissions): self
    {
        return $this->afterCreating(function (Role $role) use ($permissions): void {
            $names = array_map(
                static fn (PermissionType|string $permission): string => $permission instanceof PermissionType
                    ? $permission->value
                    : $permission,
                $permissions,
            );

            // Create any missing permission rows so a test can grant a
            // permission without depending on the seeder having run.
            foreach ($names as $name) {
                $type = PermissionType::tryFrom($name);

                Permission::query()->firstOrCreate(
                    ['name' => $name],
                    [
                        'label' => $type?->label() ?? Str::headline($name),
                        'group' => $type?->group() ?? 'General',
                    ],
                );
            }

            $role->syncPermissions($names);
        });
    }
}
