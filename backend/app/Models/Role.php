<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PermissionType;
use App\Enums\RoleType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named bundle of permissions assignable to staff.
 *
 * @property int $id
 * @property string $name
 * @property string $label
 * @property string|null $description
 * @property int $level
 * @property bool $is_system
 * @property-read Collection<int, Permission> $permissions
 */
class Role extends Model
{
    /** @use HasFactory<\Database\Factories\RoleFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'label',
        'description',
        'level',
        'is_system',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role')->withTimestamps();
    }

    /**
     * @return BelongsToMany<Admin, $this>
     */
    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(Admin::class, 'admin_role')
            ->withPivot(['assigned_by', 'assigned_at'])
            ->withTimestamps();
    }

    public function type(): ?RoleType
    {
        return RoleType::tryFrom($this->name);
    }

    /**
     * Whether this role implicitly holds every permission.
     */
    public function hasImplicitAllAccess(): bool
    {
        return $this->type()?->hasImplicitAllAccess() ?? false;
    }

    public function isSuperAdmin(): bool
    {
        return $this->type() === RoleType::SuperAdmin;
    }

    /**
     * Replace this role's permission set.
     *
     * `sync` rather than `attach` so removals are applied too — the admin
     * panel submits the full desired set, not a delta.
     *
     * @param  array<int, PermissionType|string>  $permissions
     */
    public function syncPermissions(array $permissions): void
    {
        $names = array_map(
            static fn (PermissionType|string $permission): string => $permission instanceof PermissionType
                ? $permission->value
                : $permission,
            $permissions,
        );

        $ids = Permission::query()->whereIn('name', $names)->pluck('id')->all();

        $this->permissions()->sync($ids);
    }

    /**
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeAssignable(Builder $query): Builder
    {
        return $query->orderByDesc('level')->orderBy('label');
    }

    public function getRouteKeyName(): string
    {
        return 'name';
    }
}
