<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\OrderStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in an order's audit trail.
 *
 * **Admin-only.** It carries the internal comment on each transition — "flagged
 * for review", "customer abusive, refusing refund" — which is exactly the
 * material that must not reach the person it describes. The customer's version
 * of the timeline is assembled separately in OrderResource, from the same rows
 * with the comments stripped.
 *
 * @mixin OrderStatusHistory
 */
final class OrderStatusHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stream' => $this->stream,

            'from_status' => $this->from_status,
            'to_status' => $this->to_status,

            // Resolved through the right enum for the stream, so a payment row
            // does not get labelled with an order status of the same name.
            'from_label' => $this->labelFor($this->from_status),
            'to_label' => $this->labelFor($this->to_status),

            'actor' => $this->actorName(),
            'comment' => $this->comment,
            'notified_customer' => $this->notified_customer,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function labelFor(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        return $this->isPaymentStream()
            ? PaymentStatus::tryFrom($status)?->label()
            : OrderStatus::tryFrom($status)?->label();
    }
}
