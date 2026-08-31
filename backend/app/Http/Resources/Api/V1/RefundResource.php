<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A refund issued against an order.
 *
 * **Admin-only.** `reason` is written by staff for staff — "goodwill, customer
 * complained twice" is a legitimate internal record and a poor thing to show
 * the customer it is about. A shopper sees that money was returned and how
 * much, through the order's totals.
 *
 * @mixin Refund
 */
final class RefundResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,

            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,

            'reason' => $this->reason,
            'issued_by' => $this->actorName(),

            'is_restocked' => $this->is_restocked,
            'is_order_level' => $this->isOrderLevel(),

            // Which lines and how many units, for a refund attributed to goods.
            'line_items' => $this->line_items,

            'gateway' => $this->gateway,
            'reference' => $this->transaction_reference,
            'failure_reason' => $this->failure_reason,

            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
