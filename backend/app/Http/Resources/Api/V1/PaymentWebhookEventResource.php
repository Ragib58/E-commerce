<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\PaymentWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One inbound callback or webhook.
 *
 * **Admin-only.** This is forensic material: it exposes which notifications
 * arrived, which were rejected, and from what address.
 *
 * `payload` is emitted despite being a raw gateway body, because it is the
 * evidence a dispute actually turns on — omitting it would leave an admin
 * reading a row that says "rejected" with no way to see why. It is safe to
 * expose here for two reasons: the gateway sanitised it before storage, so
 * credential-shaped keys are already redacted, and this endpoint is behind
 * `view_payments`.
 *
 * @mixin PaymentWebhookEvent
 */
final class PaymentWebhookEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'gateway' => $this->gateway,
            'source' => $this->source,
            'event_id' => $this->event_id,
            'event_type' => $this->event_type,
            'transaction_reference' => $this->transaction_reference,

            /*
             * The two flags that explain what happened to this delivery.
             *
             * `is_verified` false means the signature did not check out — a
             * security event. `is_processed` false with `is_verified` true
             * means it was authentic but deliberately not acted on: a
             * duplicate, or an event type this store ignores.
             */
            'is_verified' => $this->is_verified,
            'is_processed' => $this->is_processed,
            'rejection_reason' => $this->rejection_reason,

            'payload' => $this->payload,

            // Useful when a run of rejected deliveries turns out to share one
            // source.
            'ip_address' => $this->ip_address,

            'payment' => $this->whenLoaded('payment', fn (): ?array => $this->payment === null ? null : [
                'id' => $this->payment->uuid,
                'status' => $this->payment->status,
            ]),

            'order' => $this->whenLoaded('order', fn (): ?array => $this->order === null ? null : [
                'order_number' => $this->order->order_number,
            ]),

            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
