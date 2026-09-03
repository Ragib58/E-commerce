<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which kind of account a notification targets.
 *
 * Mirrors the split the whole authentication phase is built on: Admin and User
 * are separate models behind separate guards, and a notification's audience is
 * decided by which table the recipient lives in, never by a role or a flag on a
 * shared model.
 */
enum NotificationAudience: string
{
    case Customer = 'customer';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Admin => 'Admin',
        };
    }
}
