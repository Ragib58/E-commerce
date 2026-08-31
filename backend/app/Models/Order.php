<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AddressType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute as CastAttribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A placed order.
 *
 * ## What this model does and does not decide
 *
 * It answers questions about an order that is already in a state — is it
 * cancellable, what is still owed, what does the timeline look like. It does
 * **not** move an order between states. Every status change goes through
 * OrderService, because a transition has to validate against the transition
 * map, write a history row, restock where required, and notify — all in one
 * transaction. A `$order->status = X; $order->save()` anywhere would skip the
 * other four, and the resulting order looks fine while its ledger, its audit
 * trail, and its stock are all wrong.
 *
 * To make that structural rather than advisory, {@see booted()} refuses a
 * direct write to `status` or `payment_status` that did not come through the
 * service.
 *
 * ## Money
 *
 * Every amount is an integer count of minor units, matching the catalog. The
 * figures are a snapshot taken at placement and are never recomputed on read —
 * an invoice must render identically in five years. See the migration.
 *
 * @property int $id
 * @property string $uuid
 * @property string $order_number
 * @property int|null $user_id
 * @property string $customer_name
 * @property string $customer_email
 * @property string|null $customer_phone
 * @property bool $is_guest
 * @property OrderStatus $status
 * @property PaymentStatus $payment_status
 * @property PaymentMethod $payment_method
 * @property int|null $shipping_method_id
 * @property string|null $shipping_method_name
 * @property int $subtotal
 * @property int $discount_total
 * @property int $tax_total
 * @property int $shipping_total
 * @property int $grand_total
 * @property int $refunded_total
 * @property string $currency
 * @property float $tax_rate
 * @property string|null $coupon_code
 * @property string|null $customer_note
 * @property string|null $admin_note
 * @property string|null $idempotency_key
 * @property string|null $tracking_number
 * @property string|null $tracking_url
 * @property Carbon|null $placed_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $shipped_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $refunded_at
 * @property-read Collection<int, OrderItem> $items
 * @property-read int $refundable_amount
 * @property-read int $item_count
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * Columns a normal `create`/`update` may set.
     *
     * `status` and `payment_status` are deliberately absent. They are written
     * with forceFill by OrderService alone — see the class docblock and
     * {@see booted()}. `grand_total` and the other money columns are absent for
     * the same reason: they are computed, and a mass-assignable total is a
     * total something can be told.
     */
    protected $fillable = [
        'uuid',
        'order_number',
        'user_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'is_guest',
        'payment_method',
        'shipping_method_id',
        'shipping_method_name',
        'currency',
        'tax_rate',
        'coupon_code',
        'customer_note',
        'admin_note',
        'idempotency_key',
        'cart_id',
        'ip_address',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'payment_method' => PaymentMethod::class,
            'is_guest' => 'boolean',
            'subtotal' => 'integer',
            'discount_total' => 'integer',
            'tax_total' => 'integer',
            'shipping_total' => 'integer',
            'grand_total' => 'integer',
            'refunded_total' => 'integer',
            'tax_rate' => 'float',
            'placed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /**
     * Guard the status columns against writes that bypass OrderService.
     *
     * The transition map, the history row, the restock, and the notification
     * are a single indivisible unit of work. A status assigned anywhere else
     * performs one fifth of that and leaves the rest silently undone — so it is
     * refused here rather than documented as discouraged.
     *
     * `forceFill` is the escape hatch OrderService uses, and it is not a
     * loophole: forceFill bypasses `$fillable`, not model events, so this hook
     * still runs. The service sets {@see $allowStatusWrite} for the duration of
     * its transaction, which is a marker one class sets deliberately rather
     * than something a stray assignment does by accident.
     */
    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            $order->uuid ??= (string) Str::uuid();
        });

        static::updating(function (self $order): void {
            if (self::$allowStatusWrite) {
                return;
            }

            foreach (['status', 'payment_status'] as $column) {
                if ($order->isDirty($column)) {
                    throw new \LogicException(sprintf(
                        'Order %s must be changed through OrderService, which records the transition and its side effects. Direct assignment was attempted on order %s.',
                        $column,
                        $order->order_number ?? 'unsaved',
                    ));
                }
            }
        });
    }

    /**
     * Set only by OrderService, for the duration of a guarded write.
     *
     * Static because the guard is on the *call path*, not on an instance: the
     * service loads its own locked copy of the order inside the transaction, so
     * an instance flag would be set on the wrong object.
     */
    private static bool $allowStatusWrite = false;

    /**
     * Run a callback with the status guard lifted.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function withStatusWrites(\Closure $callback): mixed
    {
        $previous = self::$allowStatusWrite;
        self::$allowStatusWrite = true;

        try {
            return $callback();
        } finally {
            // Restored rather than set to false: nested calls (a refund that
            // also cancels) must not lift the guard for the outer one's
            // remaining work.
            self::$allowStatusWrite = $previous;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->oldest('id');
    }

    /**
     * @return HasMany<OrderAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(OrderAddress::class);
    }

    /**
     * @return HasOne<OrderAddress, $this>
     */
    public function shippingAddress(): HasOne
    {
        return $this->hasOne(OrderAddress::class)->where('type', AddressType::Shipping->value);
    }

    /**
     * @return HasOne<OrderAddress, $this>
     */
    public function billingAddress(): HasOne
    {
        return $this->hasOne(OrderAddress::class)->where('type', AddressType::Billing->value);
    }

    /**
     * @return BelongsTo<ShippingMethod, $this>
     */
    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    /**
     * The audit trail, oldest first — the order a timeline is read in.
     *
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->oldest('id');
    }

    /**
     * @return HasMany<OrderNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(OrderNote::class)->latest('id');
    }

    /**
     * Notes the customer is allowed to see.
     *
     * A relation rather than a filter applied at the call site, so the
     * customer-facing resource cannot forget it. See the migration for why the
     * exposing direction is the failure that matters.
     *
     * @return HasMany<OrderNote, $this>
     */
    public function customerVisibleNotes(): HasMany
    {
        return $this->notes()->where('is_customer_visible', true);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('id');
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->latest('id');
    }

    /*
    |--------------------------------------------------------------------------
    | Derived values
    |--------------------------------------------------------------------------
    */

    /**
     * How much may still be refunded.
     *
     * The ceiling every refund is checked against, so the store cannot return
     * more than it took. Derived from stored columns rather than by summing the
     * refunds relation, so it is correct without an eager load and cannot be
     * silently wrong when the relation is not loaded.
     *
     * @return CastAttribute<int, never>
     */
    protected function refundableAmount(): CastAttribute
    {
        return CastAttribute::make(
            get: fn (): int => max(0, (int) $this->grand_total - (int) $this->refunded_total),
        );
    }

    /**
     * Total units on the order.
     *
     * @return CastAttribute<int, never>
     */
    protected function itemCount(): CastAttribute
    {
        return CastAttribute::make(
            get: fn (): int => $this->relationLoaded('items')
                ? (int) $this->items->sum('quantity')
                : (int) $this->items()->sum('quantity'),
        );
    }

    /**
     * Whether the stored components still add up to the stored total.
     *
     * Cheap, and worth asserting in tests and in the admin panel: it turns a
     * corrupted order into something visible rather than a total that quietly
     * disagrees with its own lines.
     */
    public function totalsReconcile(): bool
    {
        $expected = (int) $this->subtotal
            - (int) $this->discount_total
            + (int) $this->tax_total
            + (int) $this->shipping_total;

        return $expected === (int) $this->grand_total;
    }

    /**
     * Whether an administrator may cancel this order right now.
     *
     * Both conditions, not either: the status must allow it *and* the order
     * must not already be fully refunded.
     */
    public function isCancellable(): bool
    {
        return $this->status->isCancellable();
    }

    /**
     * Whether *this customer* may cancel it themselves.
     *
     * Stricter than the admin rule — see OrderStatus::isCustomerCancellable.
     */
    public function isCustomerCancellable(): bool
    {
        return $this->status->isCustomerCancellable();
    }

    /**
     * Whether money may still be returned against this order.
     */
    public function isRefundable(): bool
    {
        return $this->status->isRefundable()
            && $this->payment_status->isRefundable()
            && $this->refundable_amount > 0;
    }

    /**
     * Whether this order belongs to the given customer.
     *
     * Used by the policy. A guest order belongs to nobody, so it returns false
     * rather than matching on email — an account that happens to share an
     * address with a guest checkout must not inherit its orders, or registering
     * with a known email would be an order-history disclosure.
     */
    public function belongsToUser(?User $user): bool
    {
        return $user !== null
            && $this->user_id !== null
            && (int) $this->user_id === (int) $user->getKey();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * @param  Builder<Order>  $query
     * @param  OrderStatus|array<int, OrderStatus|string>|string  $status
     * @return Builder<Order>
     */
    public function scopeWithStatus(Builder $query, OrderStatus|array|string $status): Builder
    {
        $values = collect(is_array($status) ? $status : [$status])
            ->map(static fn (OrderStatus|string $value): string => $value instanceof OrderStatus ? $value->value : $value)
            ->all();

        return $query->whereIn('status', $values);
    }

    /**
     * @param  Builder<Order>  $query
     * @param  PaymentStatus|array<int, PaymentStatus|string>|string  $status
     * @return Builder<Order>
     */
    public function scopeWithPaymentStatus(Builder $query, PaymentStatus|array|string $status): Builder
    {
        $values = collect(is_array($status) ? $status : [$status])
            ->map(static fn (PaymentStatus|string $value): string => $value instanceof PaymentStatus ? $value->value : $value)
            ->all();

        return $query->whereIn('payment_status', $values);
    }

    /**
     * Free-text search across the fields a support agent actually types.
     *
     * Order number, customer name, and email — not product names. Searching
     * line items would require a join that turns every keystroke in the admin
     * search box into a scan of the largest table in the schema, and an agent
     * with a customer on the phone has an order number or an email, not a
     * product.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = $term !== null ? trim($term) : null;

        if ($term === null || $term === '') {
            return $query;
        }

        // Escaped so a customer emailing from an address containing `%` does
        // not turn the lookup into a full-table wildcard scan.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

        return $query->where(function (Builder $inner) use ($escaped): void {
            $inner
                ->where('order_number', 'like', "%{$escaped}%")
                ->orWhere('customer_email', 'like', "%{$escaped}%")
                ->orWhere('customer_name', 'like', "%{$escaped}%")
                ->orWhere('customer_phone', 'like', "%{$escaped}%")
                ->orWhere('tracking_number', 'like', "%{$escaped}%");
        });
    }

    /**
     * Orders placed within a date range, inclusive.
     *
     * Filters on `placed_at` rather than `created_at`: a draft order created by
     * an admin and placed days later belongs in the period it was placed, which
     * is the figure revenue reporting must agree with.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopePlacedBetween(Builder $query, ?Carbon $from, ?Carbon $to): Builder
    {
        if ($from !== null) {
            $query->where('placed_at', '>=', $from->startOfDay());
        }

        if ($to !== null) {
            $query->where('placed_at', '<=', $to->endOfDay());
        }

        return $query;
    }

    /**
     * Orders that count toward revenue.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeRevenueBearing(Builder $query): Builder
    {
        $counting = array_values(array_map(
            static fn (OrderStatus $case): string => $case->value,
            array_filter(OrderStatus::cases(), static fn (OrderStatus $case): bool => $case->countsAsRevenue()),
        ));

        return $query->whereIn('status', $counting);
    }

    /**
     * Everything the order detail view and the invoice need, in one load.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeWithDetailRelations(Builder $query): Builder
    {
        return $query->with([
            'items',
            'addresses',
            'shippingMethod',
            'statusHistory',
            'payments',
            'refunds',
            'user',
        ]);
    }

    /**
     * The lighter load a list view needs.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeWithListingRelations(Builder $query): Builder
    {
        return $query->withCount('items')->with(['user']);
    }

    /**
     * Bound by uuid, never by id.
     *
     * A sequential integer in a URL leaks order volume and invites walking the
     * range; the policy is the access check, but the identifier should not be
     * an invitation in the first place.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
