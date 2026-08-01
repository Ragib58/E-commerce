<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sanctum token abilities, used to separate customer and admin sessions.
 *
 * Customer and staff tokens are issued with disjoint abilities. Combined with
 * separate `users` and `admins` tables (so a token's `tokenable_type` already
 * differs), this gives two independent checks before a customer token can
 * reach an admin route: the guard resolves a different model class, and the
 * ability check fails. Defence in depth against the single most damaging
 * failure this system could have.
 */
enum TokenAbility: string
{
    /** Full customer storefront access. */
    case CustomerAccess = 'customer:access';

    /** Full staff panel access, subject to per-permission checks. */
    case AdminAccess = 'admin:access';

    /**
     * Issued to a customer who has not yet verified their email.
     *
     * Deliberately narrow: it permits reading one's own profile and
     * requesting a new verification link, and nothing else. This lets an
     * unverified user see *why* they are blocked rather than being bounced to
     * a login screen with no explanation.
     */
    case CustomerUnverified = 'customer:unverified';

    public function label(): string
    {
        return match ($this) {
            self::CustomerAccess => 'Customer access',
            self::AdminAccess => 'Administrator access',
            self::CustomerUnverified => 'Customer access (email unverified)',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
