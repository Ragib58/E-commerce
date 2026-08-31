<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StockReservationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A short-lived hold on stock during checkout.
 *
 * Not a stock decrement: `products.stock` is untouched while a reservation is
 * live, and available-to-sell is `stock` minus live reservations. The
 * authoritative decrement still happens once, at placement, inside
 * InventoryService under a row lock. See the migration for why the two are kept
 * separate.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int $quantity
 * @property int|null $checkout_session_id
 * @property int|null $order_id
 * @property string $status
 * @property Carbon $expires_at
 * @property Carbon|null $released_at
 */
class StockReservation extends Model
{
    /** @use HasFactory<StockReservationFactory> */
    use HasFactory;

    /** Live: counts against availability. */
    public const STATUS_ACTIVE = 'active';

    /** Converted into a placed order's stock decrement. */
    public const STATUS_COMMITTED = 'committed';

    /** Given up, expired, or cancelled. Counts against nothing. */
    public const STATUS_RELEASED = 'released';

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'quantity',
        'checkout_session_id',
        'order_id',
        'status',
        'expires_at',
        'released_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return BelongsTo<CheckoutSession, $this>
     */
    public function checkoutSession(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Whether this hold currently counts against availability.
     *
     * Both conditions. An expired row that the sweeper has not yet marked
     * released must stop counting immediately — otherwise availability depends
     * on how recently a scheduled job ran, and stock sits unsellable in the gap
     * between expiry and cleanup.
     */
    public function isLive(?Carbon $at = null): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->expires_at->greaterThan($at ?? Carbon::now());
    }

    /**
     * Holds that count against availability right now.
     *
     * The expiry comparison is in SQL, matching {@see isLive()}. Filtering in
     * PHP after loading would make `sum()` and `exists()` lie about what is
     * actually held.
     *
     * @param  Builder<StockReservation>  $query
     * @return Builder<StockReservation>
     */
    public function scopeLive(Builder $query, ?Carbon $at = null): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where('expires_at', '>', $at ?? Carbon::now());
    }

    /**
     * Holds on one stockable.
     *
     * The null variant case is written as `whereNull` rather than
     * `where(..., null)`, which in SQL is `= NULL` and matches nothing — the
     * bug would silently report zero held units for every simple product.
     *
     * @param  Builder<StockReservation>  $query
     * @return Builder<StockReservation>
     */
    public function scopeForStockable(Builder $query, int $productId, ?int $variantId): Builder
    {
        $query->where('product_id', $productId);

        return $variantId === null
            ? $query->whereNull('product_variant_id')
            : $query->where('product_variant_id', $variantId);
    }

    /**
     * Expired holds the sweeper should mark released.
     *
     * @param  Builder<StockReservation>  $query
     * @return Builder<StockReservation>
     */
    public function scopeSweepable(Builder $query, ?Carbon $at = null): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where('expires_at', '<=', $at ?? Carbon::now());
    }
}
