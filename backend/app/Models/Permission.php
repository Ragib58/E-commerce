<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PermissionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A granular capability that can be granted to a role or directly to an admin.
 *
 * @property int $id
 * @property string $name
 * @property string $label
 * @property string $group
 * @property string|null $description
 */
class Permission extends Model
{
    /** @use HasFactory<\Database\Factories\PermissionFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'label',
        'group',
        'description',
    ];

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role')->withTimestamps();
    }

    /**
     * @return BelongsToMany<Admin, $this>
     */
    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(Admin::class, 'admin_permission')
            ->withPivot(['is_granted', 'assigned_by', 'assigned_at'])
            ->withTimestamps();
    }

    /**
     * The enum case backing this row, or null if the row names a permission
     * that no longer exists in code.
     */
    public function type(): ?PermissionType
    {
        return PermissionType::tryFrom($this->name);
    }

    /**
     * @param  Builder<Permission>  $query
     * @return Builder<Permission>
     */
    public function scopeInGroup(Builder $query, string $group): Builder
    {
        return $query->where('group', $group);
    }

    public function getRouteKeyName(): string
    {
        return 'name';
    }
}
