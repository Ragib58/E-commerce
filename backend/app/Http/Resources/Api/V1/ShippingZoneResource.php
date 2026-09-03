<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\ShippingZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ShippingZone
 */
final class ShippingZoneResource extends JsonResource
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

            'countries' => $this->countries,
            'states' => $this->states,
            'cities' => $this->cities,
            'postcodes' => $this->postcodes,

            'priority' => $this->priority,
            'is_fallback' => $this->is_fallback,
            'is_active' => $this->is_active,

            'rates' => ShippingRateResource::collection($this->whenLoaded('rates')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
