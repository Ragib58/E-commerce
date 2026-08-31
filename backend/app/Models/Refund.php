<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Money returned to a customer.
 *
 * Many per order — partial refunds compose. `orders.refunded_total` is the
 * running sum, maintained by RefundService inside the same transaction as the
 * row, and it is what the next refund is checked against so the store cannot
 * return more than it took.
 *
 * @property int $id
 * @property string $uuid
 * @property int $order_id
 * @property int|null $payment_id
 * @property int $amount
 * @property string $currency
 * @property string $status
 * @property int|null $admin_id
 * @property string|null $actor_label
 * @property string|null $reason
 * @property array<int, array{order_item_id: int, quantity: int, amount: int}>|null $line_items
 * @property bool $is_restocked
 * @property Carbon|null $refunded_at
 */
class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'order_id',
        'payment_id',
        'amount',
        'currency',
        'status',
        'admin_id',
        'actor_label',
        'reason',
        'line_items',
        'is_restocked',
        'gateway',
        'transaction_reference',
        'gateway_response',
        'failure_reason',
        'idempotency_key',
        'refunded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'line_items' => 'array',
            'is_restocked' => 'boolean',
            'gateway_response' => 'array',
            'refunded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $refund): void {
            $refund->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Whether this refund covers the whole order rather than named lines.
     */
    public function isOrderLevel(): bool
    {
        return $this->line_items === null || $this->line_items === [];
    }

    public function actorName(): string
    {
        return $this->actor_label ?? 'System';
    }

    /**
     * @param  Builder<Refund>  $query
     * @return Builder<Refund>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
