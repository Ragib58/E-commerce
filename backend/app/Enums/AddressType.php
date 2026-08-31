<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which role an address plays on an order.
 *
 * An order carries two, stored as two rows in `order_addresses` rather than two
 * sets of columns on `orders`. The column approach duplicates every field —
 * `shipping_line1`, `billing_line1`, and so on — which means every formatting,
 * validation, and display concern is written twice and drifts once.
 */
enum AddressType: string
{
    /** Where the goods go. */
    case Shipping = 'shipping';

    /** Where the invoice goes, and what the card is registered to. */
    case Billing = 'billing';

    public function label(): string
    {
        return match ($this) {
            self::Shipping => 'Shipping address',
            self::Billing => 'Billing address',
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
