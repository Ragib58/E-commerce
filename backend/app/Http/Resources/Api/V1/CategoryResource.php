<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Category
 */
final class CategoryResource extends JsonResource
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
            'description' => $this->description,
            'image' => $this->image_url,
            'banner' => $this->banner_url,
            'parent_id' => $this->parent_id,
            'depth' => $this->depth,
            'sort_order' => $this->sort_order,
            'status' => $this->status->value,

            'seo' => [
                'meta_title' => $this->meta_title ?? $this->name,
                'meta_description' => $this->meta_description,
            ],

            // Present only when the caller asked for the tree, so a flat list
            // does not imply a hierarchy it did not load.
            'children' => CategoryResource::collection($this->whenLoaded('children')),

            // withCount('products') in the query populates this.
            'products_count' => $this->whenCounted('products'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
