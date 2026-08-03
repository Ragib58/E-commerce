<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product, rendered for either the storefront or the admin panel.
 *
 * One resource serves both surfaces, with the privileged fields gated behind an
 * authenticated admin check. `cost_price` is the field that matters here:
 * exposing it publishes the store's margin on every product, so it is emitted
 * only when an admin guard actually resolves an account — never on the strength
 * of a request parameter or route name, which a client controls.
 *
 * @mixin Product
 */
final class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAdmin = $this->isAdminRequest($request);

        return [
            // The public identifier. The integer id is never exposed: it leaks
            // catalog size and invites enumeration of unpublished rows.
            'id' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'type' => $this->type->value,
            'status' => $this->status->value,

            'short_description' => $this->short_description,

            // Only on a single-product response — a 24-card grid does not need
            // 24 full descriptions, and sending them would dominate the payload.
            'description' => $this->when(
                $request->routeIs('*.show') || $isAdmin,
                $this->description,
            ),

            /*
             * Prices are integer minor units, as stored. Formatting is the
             * frontend's job: it already holds the currency and symbol from the
             * settings payload, and formatting server-side would bake a locale
             * into a cacheable response.
             */
            'pricing' => [
                'price' => $this->price,
                'discount_price' => $this->discount_price,
                'effective_price' => $this->effective_price,
                'is_on_sale' => $this->is_on_sale,
                'discount_percentage' => $this->discount_percentage,
                'is_taxable' => $this->is_taxable,
                'tax_rate' => $this->tax_rate,

                // Margin data. Admin only.
                $this->mergeWhen($isAdmin, [
                    'cost_price' => $this->cost_price,
                ]),
            ],

            'inventory' => [
                'in_stock' => $this->is_in_stock,
                'low_stock' => $this->is_low_stock,
                'allow_backorder' => $this->allow_backorder,

                /*
                 * The exact figure is admin-only. Publishing "3 left" invites
                 * competitors to meter sales precisely; the storefront needs
                 * only the boolean above, plus a low-stock nudge.
                 */
                $this->mergeWhen($isAdmin, [
                    'stock' => $this->effective_stock,
                    'low_stock_threshold' => $this->low_stock_threshold,
                ]),
            ],

            'shipping' => $this->when($this->type->isShippable(), [
                'weight' => $this->weight,
                'dimensions' => [
                    'length' => $this->length,
                    'width' => $this->width,
                    'height' => $this->height,
                ],
            ]),

            'flags' => [
                'is_featured' => $this->is_featured,
                'is_new_arrival' => $this->is_new_arrival,
                'is_best_seller' => $this->is_best_seller,
            ],

            'category' => new CategoryResource($this->whenLoaded('category')),
            'brand' => new BrandResource($this->whenLoaded('brand')),

            'media' => ProductMediaResource::collection($this->whenLoaded('media')),
            'thumbnail' => $this->thumbnailUrl(),
            'video_url' => $this->video_url,

            'variants' => ProductVariantResource::collection(
                $this->whenLoaded('activeVariants', fn () => $this->activeVariants),
            ),

            // The admin panel needs inactive variants too, to re-enable one.
            $this->mergeWhen($isAdmin, [
                'all_variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
            ]),

            'seo' => [
                'meta_title' => $this->meta_title ?? $this->name,
                'meta_description' => $this->meta_description ?? $this->short_description,
                'og_image' => $this->og_image_url ?? $this->thumbnailUrl(),
            ],

            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The thumbnail URL, without triggering a query when media is not loaded.
     *
     * Listings eager-load only the thumbnail row, so this reads from whatever
     * the query already fetched rather than lazily loading per card — the exact
     * N+1 the listing scope exists to prevent.
     */
    private function thumbnailUrl(): ?string
    {
        if (! $this->relationLoaded('media')) {
            return null;
        }

        $thumbnail = $this->media->firstWhere('is_thumbnail', true)
            ?? $this->media->firstWhere('type', 'image');

        return $thumbnail?->url;
    }

    /**
     * Whether an authenticated administrator is making this request.
     *
     * Resolves the admin guard rather than trusting the URL: a route name or
     * query parameter is client-controllable, and getting this wrong publishes
     * every product's cost price.
     */
    private function isAdminRequest(Request $request): bool
    {
        return $request->user('admin-api') !== null || $request->user('admin') !== null;
    }
}
