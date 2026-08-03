<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\ProductMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductMedia
 */
final class ProductMediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'url' => $this->url,

            // Falls back to the product name so a gallery is never published
            // with empty alt text, which is both an accessibility failure and
            // an SEO one.
            'alt_text' => $this->alt_text ?? $this->product?->name,

            'is_thumbnail' => $this->is_thumbnail,
            'sort_order' => $this->sort_order,

            // Lets the storefront swap the gallery when a variant is selected.
            'variant_id' => $this->whenNotNull($this->variant?->uuid),
        ];
    }
}
