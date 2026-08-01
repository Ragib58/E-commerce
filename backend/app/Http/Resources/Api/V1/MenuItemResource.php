<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialises a menu item and, recursively, its children.
 *
 * `whenLoaded` guards the recursion: children are only serialised when the
 * caller eager-loaded them, so the resource cannot trigger an N+1 by walking
 * the tree lazily.
 *
 * @mixin MenuItem
 */
final class MenuItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'label' => $this->label,
            'url' => $this->url,
            'icon' => $this->icon,
            'target' => $this->target,
            'children' => self::collection($this->whenLoaded('children')),
        ];
    }
}
