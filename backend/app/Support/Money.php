<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The conversion from stored minor units to a displayable string.
 *
 * Money is an integer count of the smallest currency unit everywhere in this
 * system — the catalog, the cart, orders, refunds. That is what keeps totals
 * exact: floating-point arithmetic on prices accumulates error that surfaces as
 * an invoice disagreeing with itself by a penny, and the discrepancy is
 * invisible until an accountant finds it.
 *
 * This class is the **one place** that conversion happens, and it happens at the
 * view boundary. Nothing upstream of a template should call it: a formatted
 * string that flows back into a calculation has already lost the precision the
 * integers existed to preserve.
 *
 * The API deliberately does not use this either. It emits raw minor units and
 * the currency code, leaving presentation to the client — a JSON field holding
 * "£12.50" cannot be re-formatted for a different locale, and cannot be summed.
 */
final class Money
{
    /**
     * Minor units as a decimal string, without a symbol.
     *
     * `12_50` becomes `"12.50"`.
     */
    public static function decimal(int $minorUnits, int $precision = 2): string
    {
        return number_format($minorUnits / (10 ** $precision), $precision, '.', ',');
    }

    /**
     * Minor units with the store's currency symbol.
     *
     * A negative amount renders as `-£12.50` rather than `£-12.50`: the sign
     * belongs to the quantity, not to the currency, and the second form reads
     * as a typo on a printed document.
     */
    public static function format(int $minorUnits, string $symbol = '$', int $precision = 2): string
    {
        $sign = $minorUnits < 0 ? '-' : '';

        return $sign.$symbol.self::decimal(abs($minorUnits), $precision);
    }

    /**
     * Minor units with an ISO currency code instead of a symbol.
     *
     * Used where a symbol would be ambiguous — an audit log or an email subject
     * read by someone who does not know which dollar the store trades in.
     */
    public static function withCode(int $minorUnits, string $currency, int $precision = 2): string
    {
        return sprintf('%s %s', strtoupper($currency), self::decimal($minorUnits, $precision));
    }
}
