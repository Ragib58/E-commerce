<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Admin;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A staff member's roles changed.
 *
 * Carries the before and after sets so an audit listener can record what
 * actually changed rather than only the resulting state.
 */
final class AdminRolesChanged
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<int, string>  $previousRoles
     * @param  array<int, string>  $currentRoles
     */
    public function __construct(
        public readonly Admin $admin,
        public readonly array $previousRoles,
        public readonly array $currentRoles,
        public readonly Admin $changedBy,
    ) {
    }
}
