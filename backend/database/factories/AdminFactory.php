<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RoleType;
use App\Models\Admin;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<Admin>
 */
final class AdminFactory extends Factory
{
    protected $model = Admin::class;

    protected static ?string $passwordHash = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            // Hashed once per process; bcrypt is deliberately slow and would
            // otherwise dominate the runtime of any test creating admins.
            'password' => static::$passwordHash ??= Hash::make('password'),
            'is_active' => true,
            'must_change_password' => false,
            'phone' => null,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function mustChangePassword(): self
    {
        return $this->state(fn (): array => ['must_change_password' => true]);
    }

    public function withPassword(string $password): self
    {
        return $this->state(fn (): array => ['password' => Hash::make($password)]);
    }

    /**
     * Attach a role after creation.
     *
     * Uses firstOrCreate against RoleType so a test that has not run the
     * RolesAndPermissionsSeeder still gets a role with the correct level —
     * without which every rank comparison in the policies would compare zeros.
     */
    public function withRole(RoleType|string $role): self
    {
        return $this->afterCreating(function (Admin $admin) use ($role): void {
            $type = $role instanceof RoleType ? $role : RoleType::from($role);

            $model = Role::query()->firstOrCreate(
                ['name' => $type->value],
                [
                    'label' => $type->label(),
                    'description' => $type->description(),
                    'level' => $type->level(),
                    'is_system' => true,
                ],
            );

            // Populate permissions when the role was created here rather than
            // by the seeder, so permission checks behave as they would in a
            // real installation.
            if ($model->wasRecentlyCreated && ! $type->hasImplicitAllAccess()) {
                $model->syncPermissions($type->defaultPermissions());
            }

            $admin->roles()->syncWithoutDetaching([$model->id => ['assigned_at' => now()]]);
            $admin->unsetRelation('roles');
            $admin->flushPermissionCache();
        });
    }

    public function superAdmin(): self
    {
        return $this->withRole(RoleType::SuperAdmin);
    }
}
