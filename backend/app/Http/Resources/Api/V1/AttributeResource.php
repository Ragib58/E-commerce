<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Attribute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A variant attribute and its permitted values.
 *
 * `display_type` travels with the attribute so the storefront renders swatches
 * for colour and buttons for size from one component, without hardcoding either
 * name — which is what lets an operator add "Material" and have it render
 * correctly with no frontend change.
 *
 * @mixin Attribute
 */
final class AttributeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'display_type' => $this->display_type,
            'is_filterable' => $this->is_filterable,
            'sort_order' => $this->sort_order,

            'values' => $this->whenLoaded('values', fn (): array => $this->values
                ->map(fn ($value): array => [
                    'id' => $value->id,
                    'value' => $value->value,
                    'slug' => $value->slug,
                    'colour_code' => $value->colour_code,
                    'sort_order' => $value->sort_order,
                ])
                ->values()
                ->all()),
        ];
    }
}
