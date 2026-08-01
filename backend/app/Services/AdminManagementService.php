<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\AdminCreated;
use App\Events\AdminDeactivated;
use App\Events\AdminRolesChanged;
use App\Models\Admin;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Staff account lifecycle: create, update, delete, activate, and assign roles.
 *
 * The authorization *policy* (may this actor act at all?) lives in AdminPolicy.
 * The invariants enforced here are the ones a policy cannot express, because
 * they concern the relationship between actor, target, and the roles being
 * assigned:
 *
 *   - Nobody may assign a role that outranks their own. Without this, an
 *     Admin with `manage_admins` could create a Super Admin and inherit
 *     unlimited access — the single most damaging escalation available.
 *   - The last active Super Admin cannot be deleted or deactivated, or the
 *     system becomes permanently unadministrable.
 *   - Nobody may deactivate or delete themselves, which is an easy way to
 *     lock yourself out by accident.
 */
final class AdminManagementService
{
    /**
     * Create a staff account.
     *
     * @param  array{name: string, email: string, password?: string|null, phone?: string|null, roles?: array<int, string>, is_active?: bool}  $data
     * @return array{admin: Admin, generated_password: string|null}
     *
     * @throws ValidationException
     */
    public function create(array $data, Admin $actor): array
    {
        $roleNames = $data['roles'] ?? [];

        $this->assertCanAssignRoles($roleNames, $actor);

        // A generated password is never shown again after creation, so the
        // account is forced to rotate it at first login.
        $generatedPassword = null;
        $password = $data['password'] ?? null;

        if ($password === null || $password === '') {
            $generatedPassword = $this->generatePassword();
            $password = $generatedPassword;
        }

        $admin = DB::transaction(function () use ($data, $password, $generatedPassword, $roleNames, $actor): Admin {
            $admin = Admin::query()->create([
                'name' => $data['name'],
                'email' => strtolower(trim($data['email'])),
                'password' => $password,
                'phone' => $data['phone'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'must_change_password' => $generatedPassword !== null,
            ]);

            if ($roleNames !== []) {
                $admin->syncRoles($roleNames, $actor);
            }

            return $admin;
        });

        AdminCreated::dispatch($admin, $actor);

        Log::info('Admin account created.', [
            'admin_uuid' => $admin->uuid,
            'created_by' => $actor->uuid,
            'roles' => $roleNames,
        ]);

        return [
            'admin' => $admin->load('roles'),
            'generated_password' => $generatedPassword,
        ];
    }

    /**
     * Update a staff account's own attributes. Roles are handled separately.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Admin $admin, array $data, Admin $actor): Admin
    {
        $this->assertCanManage($admin, $actor);

        $admin->fill(array_filter(
            [
                'name' => $data['name'] ?? null,
                'email' => isset($data['email']) ? strtolower(trim((string) $data['email'])) : null,
                'phone' => $data['phone'] ?? null,
            ],
            static fn (mixed $value): bool => $value !== null,
        ));

        $admin->save();

        Log::info('Admin account updated.', [
            'admin_uuid' => $admin->uuid,
            'updated_by' => $actor->uuid,
        ]);

        return $admin->refresh()->load('roles');
    }

    /**
     * Replace a staff member's roles.
     *
     * @param  array<int, string>  $roleNames
     *
     * @throws ValidationException
     */
    public function assignRoles(Admin $admin, array $roleNames, Admin $actor): Admin
    {
        $this->assertCanManage($admin, $actor);
        $this->assertCanAssignRoles($roleNames, $actor);

        // Removing the last Super Admin's role is equivalent to deleting them
        // — it strips the only account that can restore anyone else's access.
        if ($admin->isSuperAdmin() && ! in_array(\App\Enums\RoleType::SuperAdmin->value, $roleNames, strict: true)) {
            $this->assertNotLastSuperAdmin($admin, 'demote');
        }

        $previous = $admin->roles->pluck('name')->all();

        $admin->syncRoles($roleNames, $actor);

        AdminRolesChanged::dispatch($admin, $previous, $roleNames, $actor);

        Log::info('Admin roles changed.', [
            'admin_uuid' => $admin->uuid,
            'changed_by' => $actor->uuid,
            'from' => $previous,
            'to' => $roleNames,
        ]);

        return $admin->refresh()->load('roles');
    }

    /**
     * Set per-admin permission overrides.
     *
     * @param  array<string, bool>  $permissions  name => is_granted
     *
     * @throws ValidationException
     */
    public function assignPermissions(Admin $admin, array $permissions, Admin $actor): Admin
    {
        $this->assertCanManage($admin, $actor);

        // An actor cannot grant a permission they do not themselves hold —
        // otherwise `manage_admins` alone would be a path to every permission
        // in the system, by granting them to a puppet account.
        foreach ($permissions as $name => $isGranted) {
            if ($isGranted && ! $actor->hasPermission($name)) {
                throw ValidationException::withMessages([
                    'permissions' => ["You cannot grant a permission you do not hold: {$name}."],
                ]);
            }
        }

        $admin->syncDirectPermissions($permissions, $actor);

        Log::info('Admin direct permissions changed.', [
            'admin_uuid' => $admin->uuid,
            'changed_by' => $actor->uuid,
        ]);

        return $admin->refresh()->load(['roles', 'directPermissions']);
    }

    /**
     * Activate or deactivate an account.
     *
     * @throws ValidationException
     */
    public function setActive(Admin $admin, bool $isActive, Admin $actor): Admin
    {
        $this->assertCanManage($admin, $actor);

        if (! $isActive) {
            $this->assertNotSelf($admin, $actor, 'deactivate');
            $this->assertNotLastSuperAdmin($admin, 'deactivate');
        }

        $admin->forceFill(['is_active' => $isActive])->save();

        if (! $isActive) {
            // Deactivation must evict existing sessions. Leaving tokens valid
            // would mean a "deactivated" admin keeps working until their token
            // expires — which is not deactivation at all.
            $admin->tokens()->delete();

            AdminDeactivated::dispatch($admin, $actor);
        }

        Log::info($isActive ? 'Admin activated.' : 'Admin deactivated.', [
            'admin_uuid' => $admin->uuid,
            'changed_by' => $actor->uuid,
        ]);

        return $admin->refresh()->load('roles');
    }

    /**
     * Soft-delete a staff account.
     *
     * @throws ValidationException
     */
    public function delete(Admin $admin, Admin $actor): void
    {
        $this->assertCanManage($admin, $actor);
        $this->assertNotSelf($admin, $actor, 'delete');
        $this->assertNotLastSuperAdmin($admin, 'delete');

        DB::transaction(function () use ($admin): void {
            $admin->tokens()->delete();
            $admin->delete();
        });

        Log::warning('Admin account deleted.', [
            'admin_uuid' => $admin->uuid,
            'deleted_by' => $actor->uuid,
        ]);
    }

    /**
     * The actor must strictly outrank the target.
     *
     * Equal rank is refused so two peers cannot delete each other, and so an
     * Admin cannot act on another Admin.
     *
     * @throws ValidationException
     */
    private function assertCanManage(Admin $target, Admin $actor): void
    {
        if ($actor->is($target)) {
            return; // Self-edits of name/phone are allowed; the callers guard the rest.
        }

        if ($actor->isSuperAdmin()) {
            return;
        }

        if (! $actor->outranks($target)) {
            throw ValidationException::withMessages([
                'admin' => ['You cannot manage an administrator of equal or higher rank.'],
            ]);
        }
    }

    /**
     * Nobody may assign a role at or above their own level.
     *
     * @param  array<int, string>  $roleNames
     *
     * @throws ValidationException
     */
    private function assertCanAssignRoles(array $roleNames, Admin $actor): void
    {
        if ($roleNames === []) {
            return;
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, Role> $roles */
        $roles = Role::query()->whereIn('name', $roleNames)->get();

        $missing = array_diff($roleNames, $roles->pluck('name')->all());

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'roles' => ['Unknown role: ' . implode(', ', $missing) . '.'],
            ]);
        }

        // Super Admin may assign anything, including Super Admin.
        if ($actor->isSuperAdmin()) {
            return;
        }

        $actorLevel = $actor->roleLevel();

        foreach ($roles as $role) {
            if ($role->level >= $actorLevel) {
                throw ValidationException::withMessages([
                    'roles' => ["You cannot assign the role \"{$role->label}\", which ranks at or above your own."],
                ]);
            }
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertNotSelf(Admin $target, Admin $actor, string $action): void
    {
        if ($actor->is($target)) {
            throw ValidationException::withMessages([
                'admin' => ["You cannot {$action} your own account."],
            ]);
        }
    }

    /**
     * Refuse an action that would remove the final active Super Admin.
     *
     * @throws ValidationException
     */
    private function assertNotLastSuperAdmin(Admin $admin, string $action): void
    {
        if (! $admin->isSuperAdmin()) {
            return;
        }

        $remaining = Admin::query()
            ->active()
            ->whereKeyNot($admin->getKey())
            ->whereHas('roles', fn ($query) => $query->where('name', \App\Enums\RoleType::SuperAdmin->value))
            ->count();

        if ($remaining === 0) {
            throw ValidationException::withMessages([
                'admin' => ["You cannot {$action} the last active Super Admin. Assign the role to another account first."],
            ]);
        }
    }

    /**
     * Generate a random initial password.
     *
     * Str::password draws from a mixed alphabet using a CSPRNG, so it is
     * suitable for a credential rather than merely random-looking.
     */
    private function generatePassword(): string
    {
        return Str::password(16, letters: true, numbers: true, symbols: true, spaces: false);
    }
}
