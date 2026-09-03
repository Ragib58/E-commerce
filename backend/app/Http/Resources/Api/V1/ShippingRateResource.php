<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\ShippingRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ShippingRate
 */
final class ShippingRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'method' => $this->whenLoaded('shippingMethod', fn (): ?array => $this->shippingMethod === null ? null : [
                'id' => $this->shippingMethod->uuid,
                'name' => $this->shippingMethod->name,
            ]),

            'zone' => $this->whenLoaded('zone', fn (): ?array => $this->zone === null ? null : [
                'id' => $this->zone->uuid,
                'name' => $this->zone->name,
            ]),

            'rate' => $this->rate,
            'free_above' => $this->free_above,
            'min_subtotal' => $this->min_subtotal,
            'max_subtotal' => $this->max_subtotal,
            'min_days' => $this->min_days,
            'max_days' => $this->max_days,
            'is_active' => $this->is_active,
        ];
    }
}
