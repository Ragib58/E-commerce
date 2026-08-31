<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised after an order is committed.
 *
 * Keeps OrderService concerned only with creating the order: listeners decide
 * what placement implies — the confirmation email, the warehouse notification,
 * the analytics event, purging cached availability.
 *
 * Dispatched post-commit, so a listener never emails a customer about an order
 * whose transaction subsequently rolled back.
 */
final class OrderPlaced
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Order $order,
    ) {
    }
}
