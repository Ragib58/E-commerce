<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\StockMovement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised after a stock level changes and the movement is committed.
 *
 * Keeps InventoryService concerned only with the ledger: listeners decide what
 * a stock change implies (purging the storefront's cached availability,
 * notifying a purchasing system).
 *
 * Dispatched post-commit, so a listener never observes a change that
 * subsequently rolls back.
 */
final class StockAdjusted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly StockMovement $movement,
    ) {
    }

    /**
     * Whether this change crossed the in-stock / out-of-stock boundary.
     *
     * Only these transitions alter what the storefront renders — a drop from
     * 90 to 80 changes no page — so listeners use this to avoid purging caches
     * on every routine sale.
     */
    public function changesAvailability(): bool
    {
        $wasAvailable = $this->movement->quantity_before > 0;
        $isAvailable = $this->movement->quantity_after > 0;

        return $wasAvailable !== $isAvailable;
    }
}
