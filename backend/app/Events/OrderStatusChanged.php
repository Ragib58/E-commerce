<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised after an order's status moves and the transition is committed.
 *
 * Dispatched post-commit for the same reason StockAdjusted is: a listener that
 * emails "your order has shipped" must not fire for a transition that then
 * rolls back, and a customer who receives that email for an order still sitting
 * in the warehouse has been told something false by the system of record.
 */
final class OrderStatusChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly OrderStatus $status,
        public readonly ?string $comment = null,
    ) {
    }

    /**
     * Whether the customer should hear about this change.
     *
     * Listeners read this rather than listing statuses themselves, so the
     * decision lives with the enum and a new state cannot silently start or
     * stop generating mail.
     */
    public function shouldNotifyCustomer(): bool
    {
        return $this->status->notifiesCustomer();
    }
}
