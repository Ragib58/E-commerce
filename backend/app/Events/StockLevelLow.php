<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised when stock crosses *into* its low band.
 *
 * Fired on the transition only, not on every subsequent sale while the level
 * stays low — an alert that repeats on each order is one nobody reads.
 */
final class StockLevelLow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Product|ProductVariant $stockable,
        public readonly int $remaining,
    ) {
    }

    public function isOutOfStock(): bool
    {
        return $this->remaining <= 0;
    }

    /**
     * Label identifying the affected item in a notification.
     */
    public function label(): string
    {
        if ($this->stockable instanceof ProductVariant) {
            return sprintf(
                '%s — %s',
                $this->stockable->product?->name ?? 'Unknown product',
                $this->stockable->name ?? $this->stockable->sku,
            );
        }

        return $this->stockable->name;
    }
}
