<?php

declare(strict_types=1);

namespace App\Models;

use App\Notifications\CustomerPasswordResetNotification;
use App\Notifications\CustomerVerifyEmailNotification;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * A customer account.
 *
 * `is_admin` was removed in the authentication phase: staff are a separate
 * model (App\Models\Admin) in a separate table. There is deliberately no
 * column on this model that can grant administrative access.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property \Illuminate\Support\Carbon|null $last_login_at
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'email',
        'password',
        'phone',
        'avatar_path',
        'date_of_birth',
        'is_active',
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
            'date_of_birth' => 'date',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * Storefront-specific reset mail, linking to the Next.js reset page rather
     * than a Laravel route.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new CustomerPasswordResetNotification($token, $this->email));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new CustomerVerifyEmailNotification());
    }

    /**
     * Whether this account may sign in.
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
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->whereNotNull('email_verified_at');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
