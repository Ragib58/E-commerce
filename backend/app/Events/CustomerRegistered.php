<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A customer account was created.
 *
 * Distinct from Laravel's built-in Registered event, which exists to trigger
 * email verification. This one carries business meaning and is the hook for
 * welcome sequences, analytics, and CRM sync in later phases.
 */
final class CustomerRegistered
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
    ) {
    }
}
