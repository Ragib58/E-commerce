<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The direction a stock movement pushes inventory.
 *
 * Every change to a stock level is recorded as a movement, and each movement
 * carries one of these. The ledger is append-only: a correction is a new
 * movement, never an edit to an old one, so the history always reconstructs the
 * current figure by summation.
 */
enum StockMovementType: string
{
    /** Stock arriving — a purchase order, a return to shelf. */
    case Increase = 'increase';

    /** Stock leaving — a sale, damage, theft. */
    case Decrease = 'decrease';

    /**
     * A stock take that asserts an absolute figure.
     *
     * The delta is computed against the level at the time of the count rather
     * than supplied, which is what makes a physical count safe to record even
     * when the system figure was wrong.
     */
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Increase => 'Increase',
            self::Decrease => 'Decrease',
            self::Adjustment => 'Adjustment',
        };
    }

    /**
     * The sign this type applies to a supplied quantity.
     *
     * Adjustments return 0 because their delta is derived from the counted
     * figure, not from the quantity's sign — callers must not use this to
     * compute an adjustment's effect.
     */
    public function sign(): int
    {
        return match ($this) {
            self::Increase => 1,
            self::Decrease => -1,
            self::Adjustment => 0,
        };
    }

    public function isAbsolute(): bool
    {
        return $this === self::Adjustment;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
