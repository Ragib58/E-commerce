<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\ShippingMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A delivery method, for the admin panel.
 *
 * @mixin ShippingMethod
 */
final class ShippingMethodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,

            'rate' => $this->rate,
            'free_above' => $this->free_above,

            'min_days' => $this->min_days,
            'max_days' => $this->max_days,
            'estimate' => $this->estimateLabel(),

            'countries' => $this->countries,
            'min_subtotal' => $this->min_subtotal,
            'max_subtotal' => $this->max_subtotal,

            'is_active' => $this->is_active,
            'requires_address' => $this->requires_address,
            'sort_order' => $this->sort_order,

            // Zone-specific overrides, when they were eager-loaded. Absent
            // from the list view — a method's rate rows are edited from its
            // own detail screen, not fetched per row in a table.
            'rates' => ShippingRateResource::collection($this->whenLoaded('rates')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
