<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an order sits in its lifecycle.
 *
 * ## Transitions are data, not scattered conditionals
 *
 * The legal moves live in {@see allowedTransitions()}, and every status change
 * in the application is validated against it. That matters more than it looks:
 * without one authoritative map, "can this order be cancelled?" gets answered
 * independently in the admin controller, the customer controller, the refund
 * path, and whatever ships next — and the four answers drift. A shipped order
 * that one path lets a customer cancel is a parcel in a van with a refund
 * already issued against it.
 *
 * The graph is deliberately *forward-only* with two exits. An order does not go
 * back from Shipped to Packed: the physical event already happened, and a
 * status that can retreat makes the history meaningless. A mistake is corrected
 * by moving forward to Cancelled or Returned, exactly as the inventory ledger
 * corrects with an opposing movement rather than an edit.
 *
 * ## Terminal states
 *
 * Delivered is *not* terminal — a delivered order can still be returned, and
 * modelling it as an endpoint would make returns unrepresentable. Cancelled,
 * Refunded, and Returned are the true endpoints; Returned may still move to
 * Refunded because the goods coming back and the money going out are two
 * events, often days apart, and collapsing them would lose the window where a
 * warehouse has the item but finance has not yet paid.
 */
enum OrderStatus: string
{
    /** Placed, but not yet accepted by the store. Awaiting payment or review. */
    case Pending = 'pending';

    /** Accepted. Payment cleared or offline payment agreed. */
    case Confirmed = 'confirmed';

    /** Being picked and prepared. */
    case Processing = 'processing';

    /** Boxed and awaiting handover to the carrier. */
    case Packed = 'packed';

    /** With the carrier. */
    case Shipped = 'shipped';

    /** Received by the customer. */
    case Delivered = 'delivered';

    /** Called off before dispatch. Stock returns to the shelf. */
    case Cancelled = 'cancelled';

    /** Sent back by the customer. Stock returns to the shelf. */
    case Returned = 'returned';

    /** Money returned to the customer. */
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::Processing => 'Processing',
            self::Packed => 'Packed',
            self::Shipped => 'Shipped',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
            self::Returned => 'Returned',
            self::Refunded => 'Refunded',
        };
    }

    /**
     * Colour token for the status pill in the admin panel and storefront.
     */
    public function colour(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Confirmed => 'sky',
            self::Processing => 'indigo',
            self::Packed => 'violet',
            self::Shipped => 'blue',
            self::Delivered => 'emerald',
            self::Cancelled => 'slate',
            self::Returned => 'orange',
            self::Refunded => 'rose',
        };
    }

    /**
     * Customer-facing description of what is happening right now.
     *
     * Written for the tracking page. Deliberately vaguer than the label about
     * internal operations — a shopper does not need to know the difference
     * between "picked" and "packed", they need to know whether to expect a
     * knock at the door.
     */
    public function customerDescription(): string
    {
        return match ($this) {
            self::Pending => 'We have received your order and are awaiting confirmation.',
            self::Confirmed => 'Your order is confirmed and will be prepared shortly.',
            self::Processing => 'We are preparing your order.',
            self::Packed => 'Your order is packed and ready to leave our warehouse.',
            self::Shipped => 'Your order is on its way.',
            self::Delivered => 'Your order has been delivered.',
            self::Cancelled => 'This order was cancelled.',
            self::Returned => 'This order has been returned.',
            self::Refunded => 'This order has been refunded.',
        };
    }

    /**
     * The complete transition map: current state => states reachable from it.
     *
     * The single source of truth for order progression. Every status write in
     * the application passes through {@see canTransitionTo()}, so adding a
     * state or a shortcut is a change to this array and nothing else.
     *
     * @return array<string, array<int, self>>
     */
    public static function allowedTransitions(): array
    {
        return [
            /*
             * Pending may jump straight to Processing. Stores that take payment
             * on capture rather than authorisation confirm and begin picking in
             * the same motion, and forcing an intermediate click that always
             * happens is ceremony, not control.
             */
            self::Pending->value => [
                self::Confirmed,
                self::Processing,
                self::Cancelled,
            ],

            self::Confirmed->value => [
                self::Processing,
                self::Packed,
                self::Cancelled,
            ],

            self::Processing->value => [
                self::Packed,
                self::Shipped,
                self::Cancelled,
            ],

            /*
             * Cancellable up to and including Packed: the parcel is still on
             * the premises, so it can be unpacked and the stock returned. Once
             * it is Shipped it cannot — see isCancellable().
             */
            self::Packed->value => [
                self::Shipped,
                self::Cancelled,
            ],

            /*
             * Shipped may go to Returned without passing through Delivered: a
             * parcel refused at the door or lost and recovered comes back
             * without ever having been received.
             */
            self::Shipped->value => [
                self::Delivered,
                self::Returned,
            ],

            self::Delivered->value => [
                self::Returned,
                // A goodwill refund without a physical return happens — a
                // damaged item the store does not want back.
                self::Refunded,
            ],

            // Cancelling before payment costs nothing; cancelling after it
            // clears owes the customer money.
            self::Cancelled->value => [
                self::Refunded,
            ],

            // The goods are back. The money is a separate, later event.
            self::Returned->value => [
                self::Refunded,
            ],

            // Terminal. Money returned and goods accounted for.
            self::Refunded->value => [],
        ];
    }

    /**
     * Whether this order may move to the given status.
     */
    public function canTransitionTo(self $target): bool
    {
        // A no-op transition is refused rather than quietly accepted. Silently
        // succeeding would write a history row saying the status changed to
        // what it already was, and a timeline full of those is noise that hides
        // the real events.
        if ($target === $this) {
            return false;
        }

        return in_array($target, self::allowedTransitions()[$this->value] ?? [], strict: true);
    }

    /**
     * States reachable from this one.
     *
     * @return array<int, self>
     */
    public function nextStates(): array
    {
        return self::allowedTransitions()[$this->value] ?? [];
    }

    /**
     * Whether the order is finished and will not move again on its own.
     */
    public function isTerminal(): bool
    {
        return $this->nextStates() === [];
    }

    /**
     * Whether stock is currently committed to this order.
     *
     * Drives the restock decision: cancelling a Processing order must return
     * its units to the shelf, whereas cancelling one that never held stock
     * would create inventory out of nothing. Deliberately a property of the
     * status rather than a flag on the order, so the two cannot disagree.
     */
    public function holdsStock(): bool
    {
        return in_array($this, [
            self::Pending,
            self::Confirmed,
            self::Processing,
            self::Packed,
            self::Shipped,
            self::Delivered,
        ], strict: true);
    }

    /**
     * Whether an administrator may cancel an order in this state.
     *
     * Stops at Packed. After that the parcel is with a carrier and the store no
     * longer controls it — the correct instrument is a return, which tracks the
     * goods coming back rather than pretending they never left.
     */
    public function isCancellable(): bool
    {
        return in_array($this, [
            self::Pending,
            self::Confirmed,
            self::Processing,
            self::Packed,
        ], strict: true);
    }

    /**
     * Whether a *customer* may cancel an order in this state themselves.
     *
     * Stricter than the admin rule and intentionally so. Once picking has
     * begun, a self-service cancellation races the warehouse: staff may already
     * be holding the item. Beyond Confirmed the customer is directed to support,
     * where a human can check whether the parcel has physically moved.
     */
    public function isCustomerCancellable(): bool
    {
        return in_array($this, [
            self::Pending,
            self::Confirmed,
        ], strict: true);
    }

    /**
     * Whether the order has reached the point where money may be returned.
     */
    public function isRefundable(): bool
    {
        return in_array($this, [
            self::Confirmed,
            self::Processing,
            self::Packed,
            self::Shipped,
            self::Delivered,
            self::Cancelled,
            self::Returned,
        ], strict: true);
    }

    /**
     * Whether the order still counts toward revenue.
     *
     * Reporting reads this rather than listing statuses inline, so a new state
     * cannot silently start or stop counting as a sale.
     */
    public function countsAsRevenue(): bool
    {
        return ! in_array($this, [
            self::Cancelled,
            self::Refunded,
            self::Returned,
        ], strict: true);
    }

    /**
     * Whether the customer should be emailed when the order enters this state.
     *
     * Pending is excluded: the order confirmation email already covers it, and
     * a second message a second later reads as a duplicate.
     */
    public function notifiesCustomer(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * Position in the fulfilment progress bar, or null for states that sit off
     * the happy path.
     *
     * Cancelled, Returned, and Refunded return null rather than a number
     * because they are not further along anything — rendering them as step 7 of
     * 7 would show a cancelled order as complete.
     */
    public function progressStep(): ?int
    {
        return match ($this) {
            self::Pending => 1,
            self::Confirmed => 2,
            self::Processing => 3,
            self::Packed => 4,
            self::Shipped => 5,
            self::Delivered => 6,
            self::Cancelled, self::Returned, self::Refunded => null,
        };
    }

    /**
     * Total steps on the happy path, for rendering "step 3 of 6".
     */
    public static function progressTotal(): int
    {
        return 6;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, array{value: string, label: string, colour: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
                'colour' => $case->colour(),
            ],
            self::cases(),
        );
    }
}
