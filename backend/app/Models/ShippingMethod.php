<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ShippingMethodFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A delivery service the store offers.
 *
 * The rate is read from this row at checkout and never accepted from a client —
 * the same rule the catalog follows for prices. A checkout session stores the
 * method's id; {@see rateFor()} computes what it costs.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property int $rate
 * @property int|null $free_above
 * @property int|null $min_days
 * @property int|null $max_days
 * @property array<int, string>|null $countries
 * @property int|null $min_subtotal
 * @property int|null $max_subtotal
 * @property bool $is_active
 * @property bool $requires_address
 * @property int $sort_order
 */
class ShippingMethod extends Model
{
    /** @use HasFactory<ShippingMethodFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'name',
        'code',
        'description',
        'rate',
        'free_above',
        'min_days',
        'max_days',
        'countries',
        'min_subtotal',
        'max_subtotal',
        'is_active',
        'requires_address',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'integer',
            'free_above' => 'integer',
            'min_days' => 'integer',
            'max_days' => 'integer',
            'countries' => 'array',
            'min_subtotal' => 'integer',
            'max_subtotal' => 'integer',
            'is_active' => 'boolean',
            'requires_address' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $method): void {
            $method->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * What this method costs for a given order subtotal.
     *
     * The one place a shipping charge is decided. Checkout, order placement,
     * and the quote endpoint all call this, so a free-shipping threshold cannot
     * apply in the quote and then fail to apply on the order — which is the
     * discrepancy customers notice and report as being overcharged.
     */
    public function rateFor(int $subtotal): int
    {
        if ($this->free_above !== null && $subtotal >= $this->free_above) {
            return 0;
        }

        return (int) $this->rate;
    }

    /**
     * Whether this method may be offered for a given destination and subtotal.
     *
     * Availability is decided *before* the method is shown, not after it is
     * chosen. Offering an option and then rejecting it at the final step is the
     * checkout equivalent of a broken link.
     */
    public function isAvailableFor(int $subtotal, ?string $countryCode = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->min_subtotal !== null && $subtotal < $this->min_subtotal) {
            return false;
        }

        if ($this->max_subtotal !== null && $subtotal > $this->max_subtotal) {
            return false;
        }

        // A null or empty country list means unconstrained, not "no countries".
        // The inverted reading would silently disable every method the moment
        // the column was added.
        if ($this->countries !== null && $this->countries !== [] && $countryCode !== null) {
            $permitted = array_map(strtoupper(...), $this->countries);

            if (! in_array(strtoupper($countryCode), $permitted, strict: true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The delivery estimate as a phrase, or null when none is configured.
     */
    public function estimateLabel(): ?string
    {
        if ($this->min_days === null && $this->max_days === null) {
            return null;
        }

        if ($this->min_days !== null && $this->max_days !== null) {
            if ($this->min_days === $this->max_days) {
                return $this->min_days === 1
                    ? 'Next business day'
                    : sprintf('%d business days', $this->min_days);
            }

            return sprintf('%d–%d business days', $this->min_days, $this->max_days);
        }

        $days = $this->min_days ?? $this->max_days;

        return sprintf('About %d business days', $days);
    }

    /**
     * The date range the parcel should arrive in.
     *
     * Business days, so a Friday order does not promise a Saturday delivery
     * that no carrier will make.
     *
     * @return array{from: ?Carbon, to: ?Carbon}
     */
    public function estimatedDelivery(?Carbon $from = null): array
    {
        $from ??= Carbon::now();

        return [
            'from' => $this->min_days !== null ? $from->copy()->addWeekdays($this->min_days) : null,
            'to' => $this->max_days !== null ? $from->copy()->addWeekdays($this->max_days) : null,
        ];
    }

    /**
     * @param  Builder<ShippingMethod>  $query
     * @return Builder<ShippingMethod>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<ShippingMethod>  $query
     * @return Builder<ShippingMethod>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('rate');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
