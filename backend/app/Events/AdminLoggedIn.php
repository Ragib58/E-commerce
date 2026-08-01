<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Admin;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A staff member signed in.
 *
 * Staff logins are security-relevant in a way customer logins are not — this
 * is the hook for alerting on logins from unexpected locations or outside
 * expected hours.
 */
final class AdminLoggedIn
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Admin $admin,
        public readonly ?string $ipAddress = null,
    ) {
    }
}
