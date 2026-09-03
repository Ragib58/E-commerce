<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ShippingRateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a shipping method costs within a particular zone and subtotal band.
 *
 * @property int $id
 * @property int $shipping_method_id
 * @property int $shipping_zone_id
 * @property int $rate
 * @property int|null $free_above
 * @property int|null $min_subtotal
 * @property int|null $max_subtotal
 * @property int|null $min_days
 * @property int|null $max_days
 * @property bool $is_active
 */
class ShippingRate extends Model
{
    /** @use HasFactory<ShippingRateFactory> */
    use HasFactory;

    protected $fillable = [
        'shipping_method_id',
        'shipping_zone_id',
        'rate',
        'free_above',
        'min_subtotal',
        'max_subtotal',
        'min_days',
        'max_days',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'integer',
            'free_above' => 'integer',
            'min_subtotal' => 'integer',
            'max_subtotal' => 'integer',
            'min_days' => 'integer',
            'max_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Keep `min_subtotal_key` in step with `min_subtotal`.
     *
     * The unique index spans `min_subtotal_key` rather than `min_subtotal` for
     * the same reason `cart_items` keys its uniqueness on `variant_key`: every
     * SQL engine treats NULLs as distinct in a unique index, which would let two
     * unbounded rows for the same (method, zone) both be inserted.
     */
    protected static function booted(): void
    {
        static::saving(function (self $rate): void {
            $rate->min_subtotal_key = $rate->min_subtotal ?? 0;
        });
    }

    /**
     * @return BelongsTo<ShippingMethod, $this>
     */
    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    /**
     * @return BelongsTo<ShippingZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    /**
     * What this rate costs for a given subtotal.
     *
     * The zone-level free-shipping threshold, mirroring
     * ShippingMethod::rateFor — kept as a separate implementation rather than
     * shared, because a rate's fallback (its own null free_above meaning "never
     * free here") must not be confused with the method's.
     */
    public function amountFor(int $subtotal): int
    {
        if ($this->free_above !== null && $subtotal >= $this->free_above) {
            return 0;
        }

        return (int) $this->rate;
    }

    /**
     * Whether this rate's subtotal band covers the given amount.
     */
    public function coversSubtotal(int $subtotal): bool
    {
        if ($this->min_subtotal !== null && $subtotal < $this->min_subtotal) {
            return false;
        }

        if ($this->max_subtotal !== null && $subtotal > $this->max_subtotal) {
            return false;
        }

        return true;
    }

    /**
     * @param  Builder<ShippingRate>  $query
     * @return Builder<ShippingRate>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The single band, if any, that covers a subtotal — cheapest checked
     * first via the caller's ordering.
     *
     * @param  Builder<ShippingRate>  $query
     * @return Builder<ShippingRate>
     */
    public function scopeCoveringSubtotal(Builder $query, int $subtotal): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('min_subtotal')->orWhere('min_subtotal', '<=', $subtotal))
            ->where(fn (Builder $q) => $q->whereNull('max_subtotal')->orWhere('max_subtotal', '>=', $subtotal));
    }
}
