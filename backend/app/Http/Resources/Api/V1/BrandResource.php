<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Brand
 */
final class BrandResource extends JsonResource
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
            'logo' => $this->logo_url,
            'description' => $this->description,
            'status' => $this->status->value,
            'sort_order' => $this->sort_order,

            'seo' => [
                'meta_title' => $this->meta_title ?? $this->name,
                'meta_description' => $this->meta_description,
            ],

            'products_count' => $this->whenCounted('products'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
