<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where the *money* for an order sits.
 *
 * Tracked separately from {@see OrderStatus} because the two genuinely move
 * independently. A cash-on-delivery order ships while payment is still Pending;
 * a prepaid order can be Paid weeks before it is Delivered; a Delivered order
 * can later become Refunded without the goods coming back. Collapsing both into
 * one column would require a state per combination — and the first time a
 * partial refund lands on a shipped order, the combined enum has nowhere to put
 * it.
 *
 * PartiallyRefunded is a first-class state rather than a boolean beside
 * Refunded. Partial refunds are ordinary — one line of five returned, a
 * shipping fee waived after a late delivery — and an order in this state still
 * has money owed to the store, which is a materially different position from
 * one that has been made whole.
 */
enum PaymentStatus: string
{
    /** Awaiting payment. The default for offline methods and unpaid orders. */
    case Pending = 'pending';

    /** Settled in full. */
    case Paid = 'paid';

    /** The gateway declined or the attempt errored. */
    case Failed = 'failed';

    /** The full amount has been returned to the customer. */
    case Refunded = 'refunded';

    /** Some of the amount has been returned; a balance remains with the store. */
    case PartiallyRefunded = 'partially_refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Refunded => 'Refunded',
            self::PartiallyRefunded => 'Partially refunded',
        };
    }

    public function colour(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Paid => 'emerald',
            self::Failed => 'rose',
            self::Refunded => 'slate',
            self::PartiallyRefunded => 'orange',
        };
    }

    /**
     * Whether the store has received money it has not given back.
     *
     * True for PartiallyRefunded: a partially refunded order is still settled
     * for the portion that was kept, and treating it as unpaid would put paid
     * orders back into the chase-the-customer queue.
     */
    public function isSettled(): bool
    {
        return in_array($this, [self::Paid, self::PartiallyRefunded], strict: true);
    }

    /**
     * Whether any money has been returned.
     */
    public function isRefunded(): bool
    {
        return in_array($this, [self::Refunded, self::PartiallyRefunded], strict: true);
    }

    /**
     * Whether a refund may still be issued against an order in this state.
     *
     * Refunding what was never captured is not a refund — it is a cancellation,
     * and processing it as a refund would send a gateway request for money the
     * store never took.
     */
    public function isRefundable(): bool
    {
        return $this->isSettled();
    }

    /**
     * Whether the store is still waiting to be paid.
     */
    public function awaitsPayment(): bool
    {
        return in_array($this, [self::Pending, self::Failed], strict: true);
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
