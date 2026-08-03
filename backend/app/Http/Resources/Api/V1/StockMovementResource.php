<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single entry in the inventory ledger.
 *
 * Admin-only throughout: movement history exposes purchasing patterns, supplier
 * cadence, and sales velocity, none of which belongs on a public surface.
 *
 * @mixin StockMovement
 */
final class StockMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),

            // Signed delta, plus both sides of the change — enough to render a
            // history row without replaying the whole ledger.
            'quantity' => $this->quantity,
            'quantity_before' => $this->quantity_before,
            'quantity_after' => $this->quantity_after,

            'product' => $this->whenLoaded('product', fn (): array => [
                'id' => $this->product->uuid,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
                'slug' => $this->product->slug,
            ]),

            'variant' => $this->whenLoaded('variant', fn (): ?array => $this->variant === null ? null : [
                'id' => $this->variant->uuid,
                'name' => $this->variant->name,
                'sku' => $this->variant->sku,
            ]),

            // Null for system-generated movements — an order pipeline
            // decrement has no admin behind it.
            'recorded_by' => $this->whenLoaded('admin', fn (): ?array => $this->admin === null ? null : [
                'id' => $this->admin->uuid,
                'name' => $this->admin->name,
            ]),

            'note' => $this->note,

            'reference' => $this->whenNotNull(
                $this->reference_type === null ? null : [
                    'type' => class_basename($this->reference_type),
                    'id' => $this->reference_id,
                ],
            ),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
