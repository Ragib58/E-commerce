<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CouponType;
use App\Services\CouponService;
use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A discount coupon.
 *
 * This model answers questions about a coupon's *shape* — is it a percentage,
 * what does it apply to, is it inside its window. It does not decide whether a
 * given cart, customer, and moment together make it *usable*, and it does not
 * compute a discount. Both of those depend on state this model cannot see —
 * the cart's contents, the shopper's order history, a row lock on `used_count`
 * — and live in {@see CouponService} instead. A model method
 * that "validates" a coupon without that context would be validating half the
 * question and returning an answer that looks complete.
 *
 * @property int $id
 * @property string $uuid
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property CouponType $type
 * @property float $value
 * @property int|null $max_discount
 * @property int|null $min_order_amount
 * @property bool $free_shipping
 * @property bool $applies_to_all
 * @property bool $first_order_only
 * @property bool $user_restricted
 * @property Carbon|null $starts_at
 * @property Carbon|null $expires_at
 * @property int|null $usage_limit
 * @property int|null $per_user_limit
 * @property int $used_count
 * @property bool $is_active
 * @property bool $is_public
 * @property int|null $created_by
 */
class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * `used_count` is deliberately absent — it is only ever incremented under a
     * row lock inside CouponService's redemption transaction. A mass-assignable
     * counter is a counter a mistaken `update()` elsewhere could desynchronise
     * from the usage ledger it is supposed to summarise.
     */
    protected $fillable = [
        'uuid',
        'code',
        'name',
        'description',
        'type',
        'value',
        'max_discount',
        'min_order_amount',
        'free_shipping',
        'applies_to_all',
        'first_order_only',
        'user_restricted',
        'starts_at',
        'expires_at',
        'usage_limit',
        'per_user_limit',
        'is_active',
        'is_public',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'value' => 'float',
            'max_discount' => 'integer',
            'min_order_amount' => 'integer',
            'free_shipping' => 'boolean',
            'applies_to_all' => 'boolean',
            'first_order_only' => 'boolean',
            'user_restricted' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'usage_limit' => 'integer',
            'per_user_limit' => 'integer',
            'used_count' => 'integer',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    /**
     * Codes are stored and compared upper-case.
     *
     * Normalising on write means every read path — the storefront's apply
     * endpoint, the admin list, a report — sees one canonical form, and a
     * unique index on the column is genuinely unique regardless of the case a
     * shopper typed.
     */
    protected static function booted(): void
    {
        static::creating(function (self $coupon): void {
            $coupon->uuid ??= (string) Str::uuid();
            $coupon->code = strtoupper(trim($coupon->code));
        });

        static::updating(function (self $coupon): void {
            if ($coupon->isDirty('code')) {
                $coupon->code = strtoupper(trim($coupon->code));
            }
        });
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    /**
     * Products this coupon is explicitly scoped to or excludes.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'coupon_product')
            ->withPivot('is_excluded');
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_coupon')
            ->withPivot(['is_excluded', 'includes_descendants']);
    }

    /**
     * Customers this coupon is restricted to, when `user_restricted` is set.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'coupon_user');
    }

    /**
     * @return HasMany<CouponUsage, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany(CouponUsage::class);
    }

    /**
     * Whether the current moment is inside the coupon's validity window.
     *
     * A null start means "live already"; a null end means "never expires" —
     * the same reading Schedulable uses for banners and homepage sections, kept
     * consistent because an operator should not have to remember two different
     * conventions for "no end date" across the admin panel.
     */
    public function isWithinWindow(?Carbon $at = null): bool
    {
        $at ??= Carbon::now();

        if ($this->starts_at !== null && $this->starts_at->greaterThan($at)) {
            return false;
        }

        return ! ($this->expires_at !== null && $this->expires_at->lessThanOrEqualTo($at));
    }

    /**
     * Whether the global redemption ceiling has been reached.
     *
     * Reads the denormalised counter, not a COUNT over `coupon_usages` — see
     * the migration for why the counter exists at all.
     */
    public function hasReachedUsageLimit(): bool
    {
        return $this->usage_limit !== null && $this->used_count >= $this->usage_limit;
    }

    /**
     * A percentage discount capped at `max_discount`, or a flat amount capped
     * at the order total — the arithmetic only, with no eligibility checks.
     *
     * `$eligibleAmount` is the subtotal of whichever lines the coupon actually
     * applies to: the whole order subtotal when `applies_to_all`, or the sum of
     * matching lines otherwise. Passing the wrong figure in is a caller error
     * this method cannot detect, which is why CouponService — not this model —
     * owns deciding what counts as eligible.
     */
    public function calculateDiscount(int $eligibleAmount): int
    {
        if ($eligibleAmount <= 0) {
            return 0;
        }

        $discount = match ($this->type) {
            CouponType::Percentage => (int) round($eligibleAmount * ($this->value / 100)),
            CouponType::Fixed => (int) round($this->value),
        };

        if ($this->max_discount !== null) {
            $discount = min($discount, $this->max_discount);
        }

        // A discount can never exceed what it is discounting. Without this
        // floor, a large fixed coupon on a small eligible amount would produce
        // a negative line — money the store owes the customer for shopping.
        return min($discount, $eligibleAmount);
    }

    /**
     * Increment the redemption counter under a row lock and return the locked
     * row, so the caller's subsequent limit checks see the post-increment
     * value rather than a stale one.
     *
     * Not a public API for "use this coupon" — CouponService::redeem() is,
     * and it is the only caller. This exists so the lock-then-increment
     * sequence lives beside the column it protects rather than being
     * reimplemented at the call site.
     */
    public function incrementUsageLocked(): self
    {
        return DB::transaction(function (): self {
            $locked = static::query()->lockForUpdate()->findOrFail($this->getKey());
            $locked->increment('used_count');

            return $locked->refresh();
        });
    }

    /**
     * @param  Builder<Coupon>  $query
     * @return Builder<Coupon>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Coupons a "current offers" listing may show without a code.
     *
     * @param  Builder<Coupon>  $query
     * @return Builder<Coupon>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true)->where('is_active', true);
    }

    /**
     * @param  Builder<Coupon>  $query
     * @return Builder<Coupon>
     */
    public function scopeByCode(Builder $query, string $code): Builder
    {
        return $query->where('code', strtoupper(trim($code)));
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
