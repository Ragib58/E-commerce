<?php

declare(strict_types=1);

namespace App\Models;

use App\Notifications\AdminPasswordResetNotification;
use App\Traits\HasPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * A staff account.
 *
 * Deliberately a separate model and table from User: staff and customers are
 * different kinds of principal, authenticate through different guards, and
 * reset passwords through different brokers. Keeping them apart means no code
 * path exists by which a customer record becomes an administrator.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $email
 * @property string $password
 * @property bool $is_active
 * @property string|null $phone
 * @property string|null $avatar_path
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property bool $must_change_password
 * @property \Illuminate\Support\Carbon|null $password_changed_at
 * @property-read Collection<int, Role> $roles
 * @property-read Collection<int, Permission> $directPermissions
 */
class Admin extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\AdminFactory> */
    use HasApiTokens;
    use HasFactory;
    use HasPermissions;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'email',
        'password',
        'is_active',
        'phone',
        'avatar_path',
        'must_change_password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            // Hashes on assignment, so a plaintext password cannot reach the
            // database even if a caller forgets to hash it.
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Admin $admin): void {
            $admin->uuid ??= (string) Str::uuid();
        });

        // Roles and direct permissions cascade at the database level, but the
        // cached permission set is in Redis and would otherwise survive the
        // account itself — and could then be served to a recycled primary key.
        static::deleted(function (Admin $admin): void {
            $admin->flushPermissionCache();
        });
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'admin_role')
            ->withPivot(['assigned_by', 'assigned_at'])
            ->withTimestamps();
    }

    /**
     * Per-admin permission overrides, both grants and revokes.
     *
     * @return BelongsToMany<Permission, $this>
     */
    public function directPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'admin_permission')
            ->withPivot(['is_granted', 'assigned_by', 'assigned_at'])
            ->withTimestamps();
    }

    /**
     * Route password reset mail through the admin-specific notification, which
     * links to the admin panel rather than the storefront.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new AdminPasswordResetNotification($token, $this->email));
    }

    /**
     * Whether this account may sign in.
     *
     * Checked at login *and* on every authenticated request, so deactivating
     * an admin takes effect immediately rather than when their token expires.
     */
    public function canAuthenticate(): bool
    {
        return $this->is_active && $this->deleted_at === null;
    }

    public function recordLogin(?string $ipAddress): void
    {
        $this->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ipAddress,
        ])->saveQuietly();
    }

    /**
     * @param  Builder<Admin>  $query
     * @return Builder<Admin>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Admin>  $query
     * @return Builder<Admin>
     */
    public function scopeWithRole(Builder $query, string $role): Builder
    {
        return $query->whereHas('roles', fn (Builder $q) => $q->where('name', $role));
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
