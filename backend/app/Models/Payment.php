<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One payment attempt against an order.
 *
 * Many per order — a declined card followed by a successful one is three rows,
 * and the failures are the evidence a fraud review reads. `orders.payment_status`
 * stays the summary so "is this paid?" needs no aggregate.
 *
 * There is no card number here and there never should be. See the migration.
 *
 * @property int $id
 * @property string $uuid
 * @property int $order_id
 * @property PaymentMethod $method
 * @property string $status
 * @property int $amount
 * @property string $currency
 * @property string|null $gateway
 * @property string|null $transaction_reference
 * @property string|null $card_brand
 * @property string|null $card_last_four
 * @property array<string, mixed>|null $gateway_response
 * @property string|null $failure_reason
 * @property Carbon|null $paid_at
 * @property Carbon|null $failed_at
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /** Outcome of a single attempt, distinct from the order's payment status. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    /**
     * The customer abandoned the payment at the gateway.
     *
     * Distinct from failed. A decline is the processor saying no; a
     * cancellation is the shopper changing their mind, and a store that chases
     * the two the same way will nag people who simply decided not to buy.
     */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * The customer has been sent to the gateway but has not returned.
     *
     * The state an abandoned checkout leaves behind, and the one the
     * reconciliation sweep polls: money may in fact have moved without anyone
     * telling us, which is exactly what a server-side verification finds.
     */
    public const STATUS_PROCESSING = 'processing';

    protected $fillable = [
        'uuid',
        'order_id',
        'method',
        'status',
        'amount',
        'currency',
        'gateway',
        'transaction_reference',
        'card_brand',
        'card_last_four',
        'gateway_response',
        'failure_reason',
        'idempotency_key',
        'initiated_at',
        'paid_at',
        'verified_at',
        'failed_at',
        'cancelled_at',
        'attempt_count',
        'redirect_url',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount' => 'integer',
            'gateway_response' => 'array',
            'attempt_count' => 'integer',
            'initiated_at' => 'datetime',
            'paid_at' => 'datetime',
            'verified_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $payment): void {
            $payment->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Whether this attempt has reached an outcome it will not leave on its own.
     *
     * A paid payment is not settled *forever* — a refund can follow — but it is
     * settled as far as this attempt is concerned, which is what the callback
     * and webhook paths need to know before deciding whether to act again.
     */
    public function isSettled(): bool
    {
        return in_array($this->status, [
            self::STATUS_PAID,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], strict: true);
    }

    /**
     * Whether the customer was sent to a gateway and never came back.
     *
     * Read by the reconciliation sweep. The window matters: a payment started
     * ten seconds ago is a shopper still typing their card number, not an
     * abandonment, and polling it would ask the gateway about a transaction
     * that has not finished happening.
     */
    public function isAwaitingReturn(int $olderThanMinutes = 5): bool
    {
        return $this->status === self::STATUS_PROCESSING
            && $this->initiated_at !== null
            && $this->initiated_at->lessThan(now()->subMinutes($olderThanMinutes));
    }

    /**
     * Whether this payment moved money that could be given back.
     */
    public function isRefundable(): bool
    {
        return $this->isPaid();
    }

    /**
     * A receipt line: "Visa ending 4242".
     *
     * Falls back to the method's label when the gateway returned no card
     * fragments, which is every offline method.
     */
    public function displayLabel(): string
    {
        if ($this->card_brand !== null && $this->card_last_four !== null) {
            return sprintf('%s ending %s', $this->card_brand, $this->card_last_four);
        }

        return $this->method->label();
    }

    /**
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PAID);
    }

    /**
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeForGateway(Builder $query, string $gateway): Builder
    {
        return $query->where('gateway', $gateway);
    }

    /**
     * Find a payment from a gateway's own reference.
     *
     * The lookup every callback and webhook performs. Scoped to the gateway as
     * well as the reference, because two processors can legitimately issue the
     * same string — matching on the reference alone could return another
     * gateway's payment and settle the wrong order.
     *
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeByReference(Builder $query, string $gateway, string $reference): Builder
    {
        return $query->where('gateway', $gateway)->where('transaction_reference', $reference);
    }

    /**
     * Payments started but never resolved, older than the given window.
     *
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeAwaitingReconciliation(Builder $query, int $olderThanMinutes = 15): Builder
    {
        return $query
            ->where('status', self::STATUS_PROCESSING)
            ->whereNotNull('initiated_at')
            ->where('initiated_at', '<', now()->subMinutes($olderThanMinutes));
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
