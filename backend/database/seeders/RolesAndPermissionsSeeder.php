<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PermissionType;
use App\Enums\RoleType;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the RBAC catalogue: every permission, and the seven system roles.
 *
 * Idempotent, and safe to re-run on a live installation:
 *
 *   - Permissions are synced *to* the enum. A permission removed from code is
 *     deleted here, so a stale row cannot linger and be granted.
 *   - Roles are created if missing, and their metadata refreshed. Their
 *     permission sets are seeded ONLY on first creation — re-running must
 *     never silently revert an operator's deliberate permission changes.
 */
final class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->syncPermissions();
            $this->syncRoles();
        });

        // Every admin's resolved permission set may now be stale.
        Cache::flush();
    }

    /**
     * Bring the permissions table in line with the enum.
     */
    private function syncPermissions(): void
    {
        foreach (PermissionType::cases() as $permission) {
            Permission::query()->updateOrCreate(
                ['name' => $permission->value],
                [
                    'label' => $permission->label(),
                    'group' => $permission->group(),
                ],
            );
        }

        // A permission dropped from the enum no longer means anything. Leaving
        // the row would let it be granted through the admin panel and silently
        // never match a check.
        $removed = Permission::query()
            ->whereNotIn('name', PermissionType::values())
            ->pluck('name');

        if ($removed->isNotEmpty()) {
            Permission::query()->whereIn('name', $removed)->delete();

            $this->command?->warn(
                'Removed permissions no longer defined in code: ' . $removed->implode(', ')
            );
        }
    }

    private function syncRoles(): void
    {
        foreach (RoleType::cases() as $roleType) {
            /** @var Role|null $existing */
            $existing = Role::query()->where('name', $roleType->value)->first();

            if ($existing !== null) {
                // Refresh presentation and ranking, but leave the permission
                // set alone — an operator may have tuned it deliberately.
                $existing->fill([
                    'label' => $roleType->label(),
                    'description' => $roleType->description(),
                    'level' => $roleType->level(),
                    'is_system' => true,
                ])->save();

                continue;
            }

            $role = Role::query()->create([
                'name' => $roleType->value,
                'label' => $roleType->label(),
                'description' => $roleType->description(),
                'level' => $roleType->level(),
                'is_system' => true,
            ]);

            // Super Admin is intentionally seeded with no rows: it bypasses
            // permission checks entirely via the Gate::before hook, so an
            // explicit list would be redundant and would drift as new
            // permissions are added.
            if (! $roleType->hasImplicitAllAccess()) {
                $role->syncPermissions($roleType->defaultPermissions());
            }
        }
    }
}
