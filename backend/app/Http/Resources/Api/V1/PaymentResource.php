<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment attempt.
 *
 * **Admin-only.** Failed attempts are fraud-review material, and the gateway
 * reference is an internal reconciliation handle.
 *
 * `gateway_response` is deliberately not emitted at all. It is a raw processor
 * payload whose shape this application does not control, so any decision about
 * which of its keys are safe to expose would be a guess that a gateway change
 * could invalidate. An admin who needs it reads the database.
 *
 * @mixin Payment
 */
final class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,

            'method' => $this->method->value,
            'method_label' => $this->method->label(),

            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,

            'gateway' => $this->gateway,
            'reference' => $this->transaction_reference,

            // Display fragments the gateway returned for a receipt line. Never
            // the instrument itself — see the payments migration.
            'label' => $this->displayLabel(),
            'card_brand' => $this->card_brand,
            'card_last_four' => $this->card_last_four,

            'failure_reason' => $this->failure_reason,

            'paid_at' => $this->paid_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
