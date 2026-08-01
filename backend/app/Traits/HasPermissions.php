<?php

declare(strict_types=1);

namespace App\Traits;

use App\Enums\PermissionType;
use App\Enums\RoleType;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\Cache;

/**
 * Role and permission resolution for staff accounts.
 *
 * Effective permissions are computed as:
 *
 *   1. Super Admin  -> every permission, unconditionally.
 *   2. Otherwise    -> union of all permissions granted by the admin's roles,
 *   3. plus         -> direct grants   (admin_permission.is_granted = true),
 *   4. minus        -> direct revokes  (admin_permission.is_granted = false).
 *
 * Revokes are applied last and win over role grants, so an exception can
 * subtract from a role rather than only add to it.
 *
 * The resolved set is cached per admin: an authorization check runs on nearly
 * every admin request, often several times per request, and re-resolving two
 * pivot tables each time is the kind of cost that only becomes visible under
 * load. The cache is invalidated whenever roles or permissions change.
 */
trait HasPermissions
{
    /** In-request memo, so repeated checks in one request hit neither DB nor Redis. */
    private ?array $resolvedPermissions = null;

    /**
     * Every permission name this account effectively holds.
     *
     * @return array<int, string>
     */
    public function effectivePermissions(): array
    {
        if ($this->resolvedPermissions !== null) {
            return $this->resolvedPermissions;
        }

        $cached = Cache::remember(
            $this->permissionCacheKey(),
            (int) config('cache.ttl.permissions', 3600),
            fn (): array => $this->resolvePermissions(),
        );

        return $this->resolvedPermissions = $cached;
    }

    /**
     * @return array<int, string>
     */
    private function resolvePermissions(): array
    {
        // Super Admin short-circuits: returning the full enum rather than a
        // stored list means a newly added permission is available immediately,
        // with no window where the top role silently lacks a new capability.
        if ($this->isSuperAdmin()) {
            return PermissionType::values();
        }

        $this->loadMissing(['roles.permissions', 'directPermissions']);

        $granted = [];

        foreach ($this->roles as $role) {
            foreach ($role->permissions as $permission) {
                $granted[$permission->name] = true;
            }
        }

        $revoked = [];

        foreach ($this->directPermissions as $permission) {
            if ((bool) $permission->pivot->is_granted) {
                $granted[$permission->name] = true;
            } else {
                // Recorded separately and applied after the loop: a revoke
                // must win even if a role processed later grants the same
                // permission.
                $revoked[$permission->name] = true;
            }
        }

        return array_values(array_diff(array_keys($granted), array_keys($revoked)));
    }

    /**
     * Whether this account holds the given permission.
     */
    public function hasPermission(PermissionType|string $permission): bool
    {
        $name = $permission instanceof PermissionType ? $permission->value : $permission;

        return in_array($name, $this->effectivePermissions(), strict: true);
    }

    /**
     * Whether this account holds at least one of the given permissions.
     *
     * @param  array<int, PermissionType|string>  $permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this account holds every one of the given permissions.
     *
     * @param  array<int, PermissionType|string>  $permissions
     */
    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    public function hasRole(RoleType|string $role): bool
    {
        $name = $role instanceof RoleType ? $role->value : $role;

        $this->loadMissing('roles');

        return $this->roles->contains(
            static fn (Role $candidate): bool => $candidate->name === $name
        );
    }

    /**
     * @param  array<int, RoleType|string>  $roles
     */
    public function hasAnyRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(RoleType::SuperAdmin);
    }

    /**
     * Highest role level this account holds; 0 when it holds none.
     *
     * Used to stop an admin acting on a peer or superior.
     */
    public function roleLevel(): int
    {
        // loadMissing rather than a bare `$this->roles`: Model::shouldBeStrict
        // is enabled outside production, so an unloaded relation would throw
        // a LazyLoadingViolation here rather than quietly issuing a query.
        $this->loadMissing('roles');

        // max() returns null on an empty collection; the cast must be applied
        // after the coalesce, not before, or `(int) null ?? 0` silently
        // evaluates to `0 ?? 0` and the fallback becomes dead code.
        return (int) ($this->roles->max('level') ?? 0);
    }

    /**
     * Whether this account outranks another.
     *
     * Strict inequality: equal-level admins cannot act on each other, which
     * prevents two Admins from deleting one another in a race.
     */
    public function outranks(self $other): bool
    {
        return $this->roleLevel() > $other->roleLevel();
    }

    /**
     * Discard the cached permission set.
     *
     * Called whenever roles or direct permissions change. Without this, a
     * revoked permission would keep working until the TTL lapsed — the exact
     * failure mode that makes permission caching dangerous if done carelessly.
     */
    public function flushPermissionCache(): void
    {
        $this->resolvedPermissions = null;

        Cache::forget($this->permissionCacheKey());
    }

    private function permissionCacheKey(): string
    {
        return sprintf('permissions:%s:%d', $this->getTable(), $this->getKey());
    }

    /**
     * Sync roles, recording who assigned them.
     *
     * @param  array<int, Role|int|string>  $roles
     */
    public function syncRoles(array $roles, ?self $assignedBy = null): void
    {
        $ids = $this->resolveRoleIds($roles);

        $payload = [];

        foreach ($ids as $id) {
            $payload[$id] = [
                'assigned_by' => $assignedBy?->getKey(),
                'assigned_at' => now(),
            ];
        }

        $this->roles()->sync($payload);

        // The relation is stale after sync; reload before anything reads it.
        $this->unsetRelation('roles');
        $this->flushPermissionCache();
    }

    /**
     * Set direct permission overrides.
     *
     * @param  array<string, bool>  $permissions  name => is_granted
     */
    public function syncDirectPermissions(array $permissions, ?self $assignedBy = null): void
    {
        $records = Permission::query()
            ->whereIn('name', array_keys($permissions))
            ->pluck('id', 'name');

        $payload = [];

        foreach ($permissions as $name => $isGranted) {
            $id = $records->get($name);

            if ($id === null) {
                continue;
            }

            $payload[$id] = [
                'is_granted' => $isGranted,
                'assigned_by' => $assignedBy?->getKey(),
                'assigned_at' => now(),
            ];
        }

        $this->directPermissions()->sync($payload);

        $this->unsetRelation('directPermissions');
        $this->flushPermissionCache();
    }

    /**
     * @param  array<int, Role|int|string>  $roles
     * @return array<int, int>
     */
    private function resolveRoleIds(array $roles): array
    {
        $ids = [];
        $names = [];

        foreach ($roles as $role) {
            if ($role instanceof Role) {
                $ids[] = (int) $role->getKey();
            } elseif (is_int($role)) {
                $ids[] = $role;
            } else {
                $names[] = $role;
            }
        }

        if ($names !== []) {
            $ids = array_merge(
                $ids,
                Role::query()->whereIn('name', $names)->pluck('id')->map(intval(...))->all(),
            );
        }

        return array_values(array_unique($ids));
    }
}
