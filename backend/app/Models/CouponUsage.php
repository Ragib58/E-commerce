<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CouponUsageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One redemption of a coupon against one order.
 *
 * Append-only, like OrderStatusHistory and StockMovement: a redemption is a
 * fact about what happened at placement, and correcting a mistake means a
 * refund or an order cancellation, never editing this row. There is no
 * `updated_at` and no code path that writes one after creation.
 *
 * @property int $id
 * @property int $coupon_id
 * @property int $order_id
 * @property int|null $user_id
 * @property string $customer_email
 * @property string $coupon_code
 * @property int $discount_amount
 * @property Carbon|null $created_at
 */
class CouponUsage extends Model
{
    /** @use HasFactory<CouponUsageFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'coupon_id',
        'order_id',
        'user_id',
        'customer_email',
        'coupon_code',
        'discount_amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_amount' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $usage): void {
            $usage->customer_email = strtolower(trim($usage->customer_email));
        });
    }

    /**
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Redemptions attributable to one shopper, whether they held an account at
     * the time or not.
     *
     * Matches by `user_id` when one is present and by email otherwise — the
     * same dual key guest order lookup uses, because a per-user coupon limit
     * has to mean something for a guest too. `orWhere` rather than two
     * separate scopes so a customer who redeemed once as a guest and once
     * signed in is counted as one person against their limit.
     *
     * @param  Builder<CouponUsage>  $query
     * @return Builder<CouponUsage>
     */
    public function scopeForCustomer(Builder $query, ?int $userId, string $email): Builder
    {
        $email = strtolower(trim($email));

        return $query->where(function (Builder $inner) use ($userId, $email): void {
            if ($userId !== null) {
                $inner->orWhere('user_id', $userId);
            }

            $inner->orWhere('customer_email', $email);
        });
    }
}
