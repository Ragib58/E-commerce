<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Menu;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Menu
 */
final class MenuResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'location' => $this->location->value,
            'items' => MenuItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
