<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductVariant
 */
final class ProductVariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user('admin-api') !== null || $request->user('admin') !== null;

        return [
            'id' => $this->uuid,
            'sku' => $this->sku,
            'name' => $this->name ?? $this->buildName(),

            /*
             * Resolved prices, with the product inheritance already applied.
             * The frontend must never re-derive this: it would have to know
             * that null means "inherit", and a variant picker that displays a
             * blank price when a variant does not override it is a bug that
             * only appears for a subset of products.
             */
            'pricing' => [
                'price' => $this->base_price,
                'discount_price' => $this->discount_price,
                'effective_price' => $this->effective_price,

                $this->mergeWhen($isAdmin, [
                    'cost_price' => $this->cost_price,
                    // The raw column, so the admin form can distinguish
                    // "inherits from product" (null) from an explicit override.
                    'own_price' => $this->price,
                ]),
            ],

            'inventory' => [
                'in_stock' => $this->is_in_stock,
                'low_stock' => $this->is_low_stock,
                'allow_backorder' => $this->allow_backorder,

                $this->mergeWhen($isAdmin, [
                    'stock' => $this->stock,
                    'low_stock_threshold' => $this->low_stock_threshold,
                ]),
            ],

            'image' => $this->image_url,
            'weight' => $this->effective_weight,

            /*
             * The attribute values that define this variant, keyed by
             * attribute slug so the storefront's option picker can match a
             * shopper's selection to a variant without string-parsing `name`.
             */
            'options' => $this->whenLoaded('attributeValues', fn (): array => $this->attributeValues
                ->map(fn ($value): array => [
                    'attribute' => $value->attribute?->slug,
                    'attribute_name' => $value->attribute?->name,
                    'display_type' => $value->attribute?->display_type,
                    'value' => $value->value,
                    'slug' => $value->slug,
                    'colour_code' => $value->colour_code,
                ])
                ->values()
                ->all()),

            'is_default' => $this->is_default,
            'sort_order' => $this->sort_order,

            $this->mergeWhen($isAdmin, [
                'is_active' => $this->is_active,
            ]),
        ];
    }
}
