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
        'paid_at',
        'failed_at',
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
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
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

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
