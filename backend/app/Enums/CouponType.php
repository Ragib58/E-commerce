<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a coupon's `value` column is interpreted.
 *
 * One column, `coupons.value`, carries a percentage in one case and minor units
 * in the other — see that migration for why. This enum is what stops the two
 * readings from being confused: every place that computes a discount asks the
 * type first, so a fixed coupon can never be accidentally treated as "off by
 * that many percent".
 */
enum CouponType: string
{
    /** `value` is a percentage, e.g. 15.0 means 15% off. */
    case Percentage = 'percentage';

    /** `value` is minor units taken directly off. */
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage discount',
            self::Fixed => 'Fixed amount discount',
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
