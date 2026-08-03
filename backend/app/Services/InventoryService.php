<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StockMovementReason;
use App\Enums\StockMovementType;
use App\Events\StockAdjusted;
use App\Events\StockLevelLow;
use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The single writer of stock levels.
 *
 * Nothing else in the application may assign to `stock`. Concentrating writes
 * here is what makes two guarantees hold at once:
 *
 *   1. Every change is journalled. A level that moved without a matching
 *      StockMovement row would make the ledger a partial record, and a partial
 *      audit trail is worse than none — it looks authoritative while being
 *      wrong.
 *
 *   2. Concurrent changes cannot oversell. Read-modify-write on a stock column
 *      is the textbook lost-update race: two requests both read 1, both write
 *      0, and two customers are promised the last unit. Every mutation below
 *      therefore runs inside a transaction and re-reads its row with
 *      `lockForUpdate()`, so the second request blocks until the first commits
 *      and then sees the true figure.
 *
 * The `stock` column on a *variable* product is a cached roll-up, never written
 * directly — variant writes recompute it.
 */
final class InventoryService
{
    /**
     * Apply a signed delta to a product or variant's stock.
     *
     * @param  int  $quantity  Positive to add, negative to remove.
     *
     * @throws ValidationException when the result would go negative without backorder.
     */
    public function adjust(
        Product|ProductVariant $stockable,
        int $quantity,
        StockMovementReason $reason = StockMovementReason::ManualEdit,
        ?Admin $actor = null,
        ?string $note = null,
        ?Model $reference = null,
    ): StockMovement {
        if ($quantity === 0) {
            throw ValidationException::withMessages([
                'quantity' => ['A stock adjustment must change the quantity by a non-zero amount.'],
            ]);
        }

        $type = $quantity > 0 ? StockMovementType::Increase : StockMovementType::Decrease;

        return $this->write($stockable, $type, $quantity, $reason, $actor, $note, $reference);
    }

    /**
     * Set stock to an absolute figure, as a physical count does.
     *
     * The delta is derived inside the lock rather than supplied by the caller.
     * That distinction matters: a stock take asserts "there are 40 on the
     * shelf", and if a sale lands between the count and the save, the correct
     * outcome is still 40 — whereas applying a delta computed from a stale read
     * would silently reintroduce the error the count was meant to fix.
     *
     * @throws ValidationException
     */
    public function setLevel(
        Product|ProductVariant $stockable,
        int $quantity,
        StockMovementReason $reason = StockMovementReason::Correction,
        ?Admin $actor = null,
        ?string $note = null,
    ): StockMovement {
        if ($quantity < 0) {
            throw ValidationException::withMessages([
                'quantity' => ['Stock cannot be set to a negative quantity.'],
            ]);
        }

        return $this->write(
            $stockable,
            StockMovementType::Adjustment,
            $quantity,
            $reason,
            $actor,
            $note,
        );
    }

    /**
     * Reserve stock for a sale.
     *
     * Separate from `adjust()` so the order pipeline reads as intent, and so
     * the reason cannot be mistyped as something that would corrupt revenue
     * reconciliation.
     *
     * @throws ValidationException when insufficient stock is available.
     */
    public function decrementForSale(
        Product|ProductVariant $stockable,
        int $quantity,
        ?Model $reference = null,
    ): StockMovement {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['A sale must remove a positive quantity.'],
            ]);
        }

        return $this->write(
            $stockable,
            StockMovementType::Decrease,
            -$quantity,
            StockMovementReason::Sale,
            null,
            null,
            $reference,
        );
    }

    /**
     * Return stock to the shelf after a cancellation or refund.
     */
    public function returnToStock(
        Product|ProductVariant $stockable,
        int $quantity,
        ?Model $reference = null,
        ?Admin $actor = null,
    ): StockMovement {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['A return must restore a positive quantity.'],
            ]);
        }

        return $this->write(
            $stockable,
            StockMovementType::Increase,
            $quantity,
            StockMovementReason::Return_,
            $actor,
            null,
            $reference,
        );
    }

    /**
     * Record the opening balance of a newly created product or variant.
     *
     * Written as a movement rather than a bare column default so the ledger
     * reconciles from zero — otherwise every product's history would begin with
     * an unexplained quantity.
     */
    public function recordOpeningBalance(
        Product|ProductVariant $stockable,
        int $quantity,
        ?Admin $actor = null,
    ): ?StockMovement {
        if ($quantity === 0) {
            return null;
        }

        return $this->write(
            $stockable,
            StockMovementType::Adjustment,
            $quantity,
            StockMovementReason::InitialStock,
            $actor,
            'Opening balance recorded at creation.',
        );
    }

    /**
     * The one place a stock level is ever written.
     *
     * @param  int  $quantity  Signed delta, or the absolute target for an adjustment.
     *
     * @throws ValidationException
     */
    private function write(
        Product|ProductVariant $stockable,
        StockMovementType $type,
        int $quantity,
        StockMovementReason $reason,
        ?Admin $actor,
        ?string $note,
        ?Model $reference = null,
    ): StockMovement {
        $product = $stockable instanceof ProductVariant ? $stockable->product : $stockable;

        if ($product !== null && ! $product->type->tracksInventory()) {
            throw ValidationException::withMessages([
                'product' => ['Stock cannot be adjusted for a digital product, which has unlimited inventory.'],
            ]);
        }

        /*
         * A variable product's own column is a derived roll-up. Allowing a
         * direct write would put it permanently at odds with the sum of its
         * variants, since variant sales recompute it afterwards.
         */
        if ($stockable instanceof Product && $stockable->type->usesVariantStock()) {
            throw ValidationException::withMessages([
                'product' => ['Stock for a variable product is held on its variants. Adjust the variant instead.'],
            ]);
        }

        $movement = DB::transaction(function () use (
            $stockable,
            $type,
            $quantity,
            $reason,
            $actor,
            $note,
            $reference,
        ): StockMovement {
            /*
             * Re-read under a row lock. The instance passed in may have been
             * loaded seconds ago — before another request changed the level —
             * so its `stock` is a hint, not a fact. Everything below uses the
             * locked figure.
             */
            $locked = $stockable->newQuery()
                ->lockForUpdate()
                ->findOrFail($stockable->getKey());

            $before = (int) $locked->stock;

            $delta = $type->isAbsolute() ? $quantity - $before : $quantity;
            $after = $before + $delta;

            if ($after < 0 && ! $locked->allow_backorder) {
                throw ValidationException::withMessages([
                    'quantity' => [sprintf(
                        'Insufficient stock: %d available, %d requested.',
                        $before,
                        abs($delta),
                    )],
                ]);
            }

            $locked->forceFill(['stock' => $after])->save();

            $movement = StockMovement::query()->create([
                'product_id' => $locked instanceof ProductVariant
                    ? $locked->product_id
                    : $locked->getKey(),
                'product_variant_id' => $locked instanceof ProductVariant ? $locked->getKey() : null,
                'type' => $type,
                'reason' => $reason,
                'quantity' => $delta,
                'quantity_before' => $before,
                'quantity_after' => $after,
                'admin_id' => $actor?->getKey(),
                'note' => $note,
                'reference_type' => $reference !== null ? $reference->getMorphClass() : null,
                'reference_id' => $reference?->getKey(),
            ]);

            // Keep the parent's cached roll-up in step, inside the same
            // transaction so a reader never sees the two disagree.
            if ($locked instanceof ProductVariant) {
                $this->syncProductStock($locked->product_id);
            }

            // Refresh the caller's instance so it reflects what was committed
            // rather than the stale value it arrived with.
            $stockable->setAttribute('stock', $after);

            return $movement;
        });

        $this->announce($movement, $stockable);

        return $movement;
    }

    /**
     * Recompute a variable product's cached stock from its active variants.
     *
     * Called inside the caller's transaction, with the variant row already
     * locked, so the sum cannot race a concurrent variant write.
     */
    public function syncProductStock(int $productId): void
    {
        $product = Product::query()->lockForUpdate()->find($productId);

        if ($product === null || ! $product->type->usesVariantStock()) {
            return;
        }

        $total = (int) ProductVariant::query()
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->sum('stock');

        if ((int) $product->stock !== $total) {
            $product->forceFill(['stock' => $total])->saveQuietly();
        }
    }

    /**
     * Products at or below their reorder point.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Product>
     */
    public function lowStockProducts(int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return Product::query()
            ->published()
            ->lowStock()
            ->with(['category', 'brand'])
            ->orderBy('stock')
            ->limit($limit)
            ->get();
    }

    /**
     * Variants at or below their reorder point.
     *
     * Reported separately from products because a variable product can sit
     * comfortably above its threshold in total while one size is about to run
     * out — which is exactly the case a buyer needs to see.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ProductVariant>
     */
    public function lowStockVariants(int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return ProductVariant::query()
            ->active()
            ->lowStock()
            ->with(['product'])
            ->whereHas('product', fn ($query) => $query->published())
            ->orderBy('stock')
            ->limit($limit)
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Product>
     */
    public function outOfStockProducts(int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return Product::query()
            ->published()
            ->outOfStock()
            ->with(['category', 'brand'])
            ->limit($limit)
            ->get();
    }

    /**
     * Headline inventory figures for the admin dashboard.
     *
     * @return array{tracked_products: int, low_stock: int, out_of_stock: int, stock_on_hand: int, stock_value: int}
     */
    public function summary(): array
    {
        $tracked = Product::query()->published()->whereNot('type', 'digital');

        return [
            'tracked_products' => (clone $tracked)->count(),
            'low_stock' => (clone $tracked)->lowStock()->count(),
            'out_of_stock' => (clone $tracked)->outOfStock()->count(),
            'stock_on_hand' => (int) (clone $tracked)->sum('stock'),

            // Valued at cost, not at retail: this figure answers "what is tied
            // up in inventory", which is a cost question. Retail valuation
            // would overstate it by the entire margin.
            'stock_value' => (int) (clone $tracked)
                ->whereNotNull('cost_price')
                ->selectRaw('COALESCE(SUM(cost_price * stock), 0) as aggregate')
                ->value('aggregate'),
        ];
    }

    /**
     * Emit the events downstream listeners react to.
     *
     * Dispatched after the transaction commits, never inside it: a listener
     * that emails a purchasing manager must not fire for a change that then
     * rolls back.
     */
    private function announce(StockMovement $movement, Product|ProductVariant $stockable): void
    {
        StockAdjusted::dispatch($movement);

        $threshold = (int) $stockable->low_stock_threshold;
        $after = $movement->quantity_after;

        // Fire only on the transition into the low band. Without the
        // `quantity_before` check, every subsequent sale of an already-low
        // product would re-alert until someone restocked.
        if ($after <= $threshold && $movement->quantity_before > $threshold) {
            StockLevelLow::dispatch($stockable, $after);

            Log::info('Stock fell to or below its reorder point.', [
                'stockable' => $stockable::class,
                'id' => $stockable->getKey(),
                'stock' => $after,
                'threshold' => $threshold,
            ]);
        }
    }
}
