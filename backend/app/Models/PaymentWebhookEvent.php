<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PaymentWebhookEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One inbound callback or webhook, as received.
 *
 * Append-only in spirit and largely in practice: a row records what arrived at
 * a moment, and that never changes. The one exception is the processing outcome
 * — `is_processed`, `processed_at`, `rejection_reason` — which is written once,
 * immediately after, by the same request that created the row.
 *
 * The model is not update-guarded the way OrderStatusHistory is, because that
 * completion write is legitimate and happens milliseconds later. What protects
 * the *content* is that nothing outside PaymentService writes here at all.
 *
 * @property int $id
 * @property string $gateway
 * @property string $source
 * @property string|null $event_id
 * @property string|null $event_type
 * @property string|null $transaction_reference
 * @property int|null $payment_id
 * @property int|null $order_id
 * @property bool $is_verified
 * @property bool $is_processed
 * @property string|null $rejection_reason
 * @property array<string, mixed>|null $payload
 * @property string|null $ip_address
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 */
class PaymentWebhookEvent extends Model
{
    /** @use HasFactory<PaymentWebhookEventFactory> */
    use HasFactory;

    /** Delivered to the webhook endpoint by the gateway's servers. */
    public const SOURCE_WEBHOOK = 'webhook';

    /** Arrived as a browser redirect from the gateway's hosted page. */
    public const SOURCE_CALLBACK = 'callback';

    /** Rows are never revised, so there is no updated_at to maintain. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'gateway',
        'source',
        'event_id',
        'event_type',
        'transaction_reference',
        'payment_id',
        'order_id',
        'is_verified',
        'is_processed',
        'rejection_reason',
        'payload',
        'ip_address',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'is_processed' => 'boolean',
            'payload' => 'array',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Mark the event as having been acted on.
     */
    public function markProcessed(): void
    {
        $this->forceFill([
            'is_processed' => true,
            'processed_at' => now(),
        ])->save();
    }

    /**
     * Record that the event was received but deliberately not acted on.
     *
     * Distinct from a failure. An unhandled event type, a duplicate, and a
     * signature mismatch all land here — the reason is what separates them, and
     * keeping the row is the point.
     */
    public function markRejected(string $reason): void
    {
        $this->forceFill([
            'is_processed' => false,
            'rejection_reason' => mb_substr($reason, 0, 512),
            'processed_at' => now(),
        ])->save();
    }

    /**
     * Events whose signature did not verify.
     *
     * The security query. One is noise; a run of them is someone probing the
     * webhook endpoint.
     *
     * @param  Builder<PaymentWebhookEvent>  $query
     * @return Builder<PaymentWebhookEvent>
     */
    public function scopeUnverified(Builder $query): Builder
    {
        return $query->where('is_verified', false);
    }

    /**
     * @param  Builder<PaymentWebhookEvent>  $query
     * @return Builder<PaymentWebhookEvent>
     */
    public function scopeForGateway(Builder $query, string $gateway): Builder
    {
        return $query->where('gateway', $gateway);
    }
}
