<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\PermissionType;
use App\Models\Admin;
use App\Models\Role;
use App\Policies\AdminPolicy;
use App\Policies\RolePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Registers policies, the Super Admin bypass, and permission gates.
 */
final class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    private array $policies = [
        Admin::class => AdminPolicy::class,
        Role::class => RolePolicy::class,
    ];

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->registerSuperAdminBypass();
        $this->registerPermissionGates();
    }

    /**
     * Abilities a Super Admin does NOT bypass.
     *
     * The bypass grants unlimited *permission*, but a handful of rules exist
     * to stop the system being rendered unadministrable — and those must bind
     * Super Admins most of all, since they are the only accounts capable of
     * causing that damage. Skipping the policy here would let a Super Admin
     * delete or deactivate their own account and lock everyone out.
     *
     * @var array<int, string>
     */
    private const NON_BYPASSABLE_ABILITIES = [
        'delete',
        'activate',
    ];

    /**
     * Super Admin passes almost every authorization check.
     *
     * Returning true from a `before` hook short-circuits the policy entirely.
     * Returning *null* — not false — is essential for everyone else and for
     * the exempted abilities: false would deny outright and prevent the policy
     * from running at all, whereas null lets the normal policy method decide.
     */
    private function registerSuperAdminBypass(): void
    {
        Gate::before(function (mixed $actor, string $ability): ?bool {
            if (! $actor instanceof Admin || ! $actor->isSuperAdmin()) {
                return null;
            }

            // Fall through to the policy, which enforces the self-protection
            // rules a Super Admin is still subject to.
            if (in_array($ability, self::NON_BYPASSABLE_ABILITIES, strict: true)) {
                return null;
            }

            return true;
        });
    }

    /**
     * Expose every permission as a Gate ability.
     *
     * This makes `$user->can('manage_settings')` work in controllers and
     * `@can('manage_settings')` work in Blade, so the admin panel can hide
     * navigation the current account cannot use without duplicating the
     * permission list in the view layer.
     */
    private function registerPermissionGates(): void
    {
        foreach (PermissionType::cases() as $permission) {
            Gate::define(
                $permission->value,
                static fn (mixed $actor): bool => $actor instanceof Admin
                    && $actor->hasPermission($permission),
            );
        }
    }
}
