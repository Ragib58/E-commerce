<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a stock movement happened.
 *
 * The type says which way stock moved; the reason says what caused it. Both are
 * recorded because they answer different questions — "we lost 40 units last
 * quarter" needs the type, "31 of them to damage" needs the reason. Shrinkage
 * reporting is impossible if a decrease from theft is indistinguishable from a
 * decrease from a sale.
 */
enum StockMovementReason: string
{
    /** Sold to a customer. */
    case Sale = 'sale';

    /** A cancelled or refunded order returning stock to the shelf. */
    case Return_ = 'return';

    /** Goods received from a supplier. */
    case Restock = 'restock';

    /** Written off — broken, expired, spoiled. */
    case Damage = 'damage';

    /** Unaccounted loss found at a stock take. */
    case Theft = 'theft';

    /** A physical count correcting the system figure. */
    case Correction = 'correction';

    /** Moved to or from another location. */
    case Transfer = 'transfer';

    /** Set when a product or variant is first created with an opening balance. */
    case InitialStock = 'initial_stock';

    /** An admin editing the stock field directly on the product form. */
    case ManualEdit = 'manual_edit';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Sale',
            self::Return_ => 'Return',
            self::Restock => 'Restock',
            self::Damage => 'Damage',
            self::Theft => 'Theft or loss',
            self::Correction => 'Stock take correction',
            self::Transfer => 'Transfer',
            self::InitialStock => 'Opening balance',
            self::ManualEdit => 'Manual edit',
        };
    }

    /**
     * Whether this reason represents inventory lost rather than sold.
     *
     * Used by shrinkage reporting, which must not count sales as losses.
     */
    public function isShrinkage(): bool
    {
        return in_array($this, [self::Damage, self::Theft], strict: true);
    }

    /**
     * Reasons an administrator may select when adjusting stock by hand.
     *
     * `Sale` and `Return` are excluded deliberately: those are written by the
     * order pipeline, and letting an admin post a manual "sale" movement would
     * corrupt revenue reconciliation against actual orders.
     *
     * @return array<int, self>
     */
    public static function manuallySelectable(): array
    {
        return [
            self::Restock,
            self::Damage,
            self::Theft,
            self::Correction,
            self::Transfer,
            self::ManualEdit,
        ];
    }

    public function isManuallySelectable(): bool
    {
        return in_array($this, self::manuallySelectable(), strict: true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::manuallySelectable(),
        );
    }
}
