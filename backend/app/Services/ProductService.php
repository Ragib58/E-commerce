<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Events\CatalogChanged;
use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Product lifecycle.
 *
 * Two responsibilities are deliberately *not* here:
 *
 *   - Stock is never written by this class. Even the opening balance goes
 *     through InventoryService, so the ledger records every unit that ever
 *     existed. A product created with `stock => 40` and no movement would start
 *     its history with 40 units from nowhere.
 *   - Variants belong to VariantService. Product edits and variant edits arrive
 *     from different admin screens and would otherwise contend for one method.
 */
final class ProductService
{
    public function __construct(
        private readonly MediaService $media,
        private readonly InventoryService $inventory,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(array $data, ?Admin $actor = null): Product
    {
        $this->assertTypeConsistency($data);

        $product = DB::transaction(function () use ($data, $actor): Product {
            $name = (string) $data['name'];

            $product = Product::query()->create([
                'name' => $name,
                'slug' => Product::generateSlug(! empty($data['slug']) ? (string) $data['slug'] : $name),
                'sku' => ! empty($data['sku']) ? (string) $data['sku'] : Product::generateSku($name),
                'short_description' => $data['short_description'] ?? null,
                'description' => $data['description'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'brand_id' => $data['brand_id'] ?? null,
                'type' => $data['type'] ?? ProductType::Simple->value,
                'price' => (int) ($data['price'] ?? 0),
                'discount_price' => isset($data['discount_price']) ? (int) $data['discount_price'] : null,
                'cost_price' => isset($data['cost_price']) ? (int) $data['cost_price'] : null,
                'tax_rate' => $data['tax_rate'] ?? null,
                'is_taxable' => $data['is_taxable'] ?? true,

                // Deliberately zero: the opening balance is posted below as a
                // movement so the ledger reconciles from nothing.
                'stock' => 0,

                'low_stock_threshold' => $data['low_stock_threshold'] ?? 5,
                'allow_backorder' => $data['allow_backorder'] ?? false,
                'weight' => $data['weight'] ?? null,
                'length' => $data['length'] ?? null,
                'width' => $data['width'] ?? null,
                'height' => $data['height'] ?? null,
                'status' => $data['status'] ?? ProductStatus::Draft->value,
                'is_featured' => $data['is_featured'] ?? false,
                'is_new_arrival' => $data['is_new_arrival'] ?? false,
                'is_best_seller' => $data['is_best_seller'] ?? false,
                'meta_title' => $data['meta_title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
                'video_url' => $data['video_url'] ?? null,
            ]);

            $this->storeOgImage($product, $data);

            $openingStock = (int) ($data['stock'] ?? 0);

            // Variable products hold no stock of their own — theirs is the sum
            // of variants, posted when each variant is created.
            if ($openingStock !== 0 && ! $product->type->usesVariantStock() && $product->type->tracksInventory()) {
                $this->inventory->recordOpeningBalance($product, $openingStock, $actor);
            }

            return $product;
        });

        CatalogChanged::dispatch('product', $product->slug, $product->status->isVisible());

        return $product->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Product $product, array $data, ?Admin $actor = null): Product
    {
        $this->assertTypeConsistency($data, $product);

        $wasPublic = $product->status->isVisible();

        $updated = DB::transaction(function () use ($product, $data, $actor): Product {
            $product->fill(array_filter([
                'name' => $data['name'] ?? null,
                'sku' => $data['sku'] ?? null,
                'short_description' => $data['short_description'] ?? null,
                'description' => $data['description'] ?? null,
                'type' => $data['type'] ?? null,
                'tax_rate' => $data['tax_rate'] ?? null,
                'low_stock_threshold' => $data['low_stock_threshold'] ?? null,
                'weight' => $data['weight'] ?? null,
                'length' => $data['length'] ?? null,
                'width' => $data['width'] ?? null,
                'height' => $data['height'] ?? null,
                'status' => $data['status'] ?? null,
                'meta_title' => $data['meta_title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
                'video_url' => $data['video_url'] ?? null,
            ], static fn (mixed $value): bool => $value !== null));

            /*
             * Nullable and boolean fields are assigned separately: array_filter
             * above drops nulls and false, so routing them through it would
             * make "clear the discount" and "unfeature this product"
             * impossible to express.
             */
            foreach (['category_id', 'brand_id', 'discount_price', 'cost_price'] as $nullable) {
                if (array_key_exists($nullable, $data)) {
                    $product->{$nullable} = $data[$nullable] === null ? null : (int) $data[$nullable];
                }
            }

            foreach (['is_taxable', 'allow_backorder', 'is_featured', 'is_new_arrival', 'is_best_seller'] as $flag) {
                if (array_key_exists($flag, $data)) {
                    $product->{$flag} = (bool) $data[$flag];
                }
            }

            if (array_key_exists('price', $data)) {
                $product->price = (int) $data['price'];
            }

            if (! empty($data['slug'])) {
                $product->slug = Product::generateSlug((string) $data['slug'], (int) $product->getKey());
            }

            $product->save();

            $this->storeOgImage($product, $data);

            /*
             * A stock figure typed into the product form is an assertion about
             * the shelf, so it is applied as an absolute set — and journalled,
             * like every other stock change.
             */
            if (array_key_exists('stock', $data)
                && ! $product->type->usesVariantStock()
                && $product->type->tracksInventory()
            ) {
                $target = (int) $data['stock'];

                if ($target !== (int) $product->stock) {
                    $this->inventory->setLevel(
                        $product,
                        $target,
                        \App\Enums\StockMovementReason::ManualEdit,
                        $actor,
                        'Set from the product edit form.',
                    );
                }
            }

            return $product;
        });

        CatalogChanged::dispatch('product', $updated->slug, $updated->status->isVisible(), $wasPublic);

        return $updated->refresh();
    }

    public function delete(Product $product): void
    {
        $slug = $product->slug;
        $wasPublic = $product->status->isVisible();

        /*
         * Soft delete only. The stock ledger, and later the order history, hold
         * foreign keys to this row; hard-deleting it would either cascade
         * through that history or leave it dangling. The catalog record is
         * evidence of what was sold.
         */
        $product->delete();

        CatalogChanged::dispatch('product', $slug, false, $wasPublic);
    }

    public function restore(Product $product): Product
    {
        $product->restore();

        CatalogChanged::dispatch('product', $product->slug, $product->status->isVisible());

        return $product;
    }

    /**
     * Publish or unpublish without touching anything else.
     */
    public function setStatus(Product $product, ProductStatus $status): Product
    {
        $wasPublic = $product->status->isVisible();

        $product->status = $status;
        $product->save();

        CatalogChanged::dispatch('product', $product->slug, $status->isVisible(), $wasPublic);

        return $product->refresh();
    }

    /**
     * Apply one action to many products.
     *
     * Runs in a single transaction so a bulk publish either lands completely or
     * not at all — a half-applied bulk action leaves an operator with no way to
     * know which rows changed.
     *
     * @param  array<int, int>  $ids
     * @return int Number of products affected.
     *
     * @throws ValidationException
     */
    public function bulkAction(array $ids, string $action, ?ProductStatus $status = null): int
    {
        if ($ids === []) {
            return 0;
        }

        $products = Product::query()->whereIn('id', $ids)->get();

        if ($products->isEmpty()) {
            return 0;
        }

        $affected = DB::transaction(function () use ($products, $action, $status): int {
            $count = 0;

            foreach ($products as $product) {
                match ($action) {
                    'publish' => $product->forceFill([
                        'status' => ProductStatus::Published,
                        'published_at' => $product->published_at ?? now(),
                    ])->save(),
                    'draft' => $product->forceFill(['status' => ProductStatus::Draft])->save(),
                    'archive' => $product->forceFill(['status' => ProductStatus::Archived])->save(),
                    'feature' => $product->forceFill(['is_featured' => true])->save(),
                    'unfeature' => $product->forceFill(['is_featured' => false])->save(),
                    'delete' => $product->delete(),
                    'status' => $product->forceFill([
                        'status' => $status ?? ProductStatus::Draft,
                    ])->save(),
                    default => throw ValidationException::withMessages([
                        'action' => ["Unknown bulk action [{$action}]."],
                    ]),
                };

                $count++;
            }

            return $count;
        });

        CatalogChanged::dispatch('product');

        return $affected;
    }

    /**
     * Attach an uploaded image to a product's gallery.
     *
     * @throws ValidationException
     */
    public function addMedia(
        Product $product,
        UploadedFile $file,
        ?string $altText = null,
        bool $isThumbnail = false,
        ?int $variantId = null,
    ): ProductMedia {
        $media = DB::transaction(function () use ($product, $file, $altText, $isThumbnail, $variantId): ProductMedia {
            $path = $this->media->store($file, 'products');

            // The first image becomes the thumbnail automatically — a product
            // with a gallery but no thumbnail renders a blank card.
            $isFirst = ! $product->media()->where('type', ProductMedia::TYPE_IMAGE)->exists();

            $media = ProductMedia::query()->create([
                'product_id' => $product->getKey(),
                'product_variant_id' => $variantId,
                'type' => ProductMedia::TYPE_IMAGE,
                'path' => $path,
                'alt_text' => $altText ?? $product->name,
                'is_thumbnail' => $isThumbnail || $isFirst,
                'sort_order' => (int) $product->media()->max('sort_order') + 1,
            ]);

            if ($media->is_thumbnail) {
                $this->demoteOtherThumbnails($product, (int) $media->getKey());
            }

            return $media;
        });

        CatalogChanged::dispatch('product', $product->slug, $product->status->isVisible());

        return $media;
    }

    /**
     * Promote one gallery image to the product thumbnail.
     */
    public function setThumbnail(Product $product, ProductMedia $media): void
    {
        if ((int) $media->product_id !== (int) $product->getKey()) {
            throw ValidationException::withMessages([
                'media' => ['That image does not belong to this product.'],
            ]);
        }

        DB::transaction(function () use ($product, $media): void {
            $media->forceFill(['is_thumbnail' => true])->save();
            $this->demoteOtherThumbnails($product, (int) $media->getKey());
        });

        CatalogChanged::dispatch('product', $product->slug, $product->status->isVisible());
    }

    public function deleteMedia(ProductMedia $media): void
    {
        $product = $media->product;
        $wasThumbnail = $media->is_thumbnail;

        DB::transaction(function () use ($media, $product, $wasThumbnail): void {
            // Videos hold an external URL, not a stored file.
            if (! $media->isVideo()) {
                $this->media->delete($media->path);
            }

            $media->delete();

            // Never leave a product without a thumbnail: promote the next
            // image rather than falling back to a placeholder.
            if ($wasThumbnail && $product !== null) {
                $next = $product->media()
                    ->where('type', ProductMedia::TYPE_IMAGE)
                    ->orderBy('sort_order')
                    ->first();

                $next?->forceFill(['is_thumbnail' => true])->save();
            }
        });

        if ($product !== null) {
            CatalogChanged::dispatch('product', $product->slug, $product->status->isVisible());
        }
    }

    /**
     * @param  array<int, array{id: int, sort_order: int}>  $items
     */
    public function reorderMedia(Product $product, array $items): void
    {
        DB::transaction(function () use ($product, $items): void {
            foreach ($items as $item) {
                $product->media()
                    ->whereKey($item['id'])
                    ->update(['sort_order' => $item['sort_order']]);
            }
        });

        CatalogChanged::dispatch('product', $product->slug, $product->status->isVisible());
    }

    private function demoteOtherThumbnails(Product $product, int $keepId): void
    {
        $product->media()
            ->whereKeyNot($keepId)
            ->where('is_thumbnail', true)
            ->update(['is_thumbnail' => false]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeOgImage(Product $product, array $data): void
    {
        $ogImage = $data['og_image'] ?? null;

        if ($ogImage instanceof UploadedFile) {
            $product->og_image = $this->media->replace($ogImage, $product->og_image, 'products');
            $product->save();

            return;
        }

        if (array_key_exists('og_image', $data) && $data['og_image'] === null && $product->og_image !== null) {
            $this->media->delete($product->og_image);
            $product->og_image = null;
            $product->save();
        }
    }

    /**
     * Refuse field combinations the product type makes meaningless.
     *
     * Caught here rather than in a form request because the rule spans the
     * submitted type and the *stored* one: a product being converted from
     * simple to variable passes a field-level check but still needs its stock
     * moved onto variants.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function assertTypeConsistency(array $data, ?Product $existing = null): void
    {
        $type = isset($data['type'])
            ? ProductType::from((string) $data['type'])
            : ($existing?->type ?? ProductType::Simple);

        if (! $type->isShippable() && ! empty($data['weight'])) {
            throw ValidationException::withMessages([
                'weight' => ['A digital product has no shipping weight.'],
            ]);
        }

        // Guard the direction that loses data. Simple -> variable is fine (the
        // operator then adds variants); variable -> simple would strand stock
        // and SKUs on variants the product no longer sells through.
        if ($existing !== null
            && $existing->type->usesVariantStock()
            && ! $type->usesVariantStock()
            && $existing->variants()->exists()
        ) {
            throw ValidationException::withMessages([
                'type' => ['Remove this product\'s variants before changing it away from a variable product.'],
            ]);
        }

        $price = $data['price'] ?? $existing?->price;
        $discount = $data['discount_price'] ?? null;

        if ($discount !== null && $price !== null && (int) $discount >= (int) $price) {
            throw ValidationException::withMessages([
                'discount_price' => ['The discount price must be lower than the regular price.'],
            ]);
        }
    }
}
