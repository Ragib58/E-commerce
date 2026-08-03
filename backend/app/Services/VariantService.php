<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\CatalogChanged;
use App\Models\Admin;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Variant lifecycle and the rules that keep a product's option matrix coherent.
 *
 * Three invariants matter, none of which the schema can express:
 *
 *   - No duplicate combinations. Two variants both meaning "Medium / Red" make
 *     the storefront's option picker ambiguous — it cannot know which SKU a
 *     shopper chose, so stock decrements from an arbitrary one.
 *   - One value per attribute per variant. A variant that is both Red and Blue
 *     is not a thing that can be picked or shipped.
 *   - At most one default variant per product.
 */
final class VariantService
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
    public function create(Product $product, array $data, ?Admin $actor = null): ProductVariant
    {
        if (! $product->type->requiresVariants()) {
            throw ValidationException::withMessages([
                'product' => ['Only a variable product can have variants.'],
            ]);
        }

        /** @var array<int, int> $valueIds */
        $valueIds = array_map('intval', $data['attribute_value_ids'] ?? []);

        $this->assertValuesAreCoherent($valueIds);
        $this->assertCombinationIsUnique($product, $valueIds);

        $variant = DB::transaction(function () use ($product, $data, $valueIds, $actor): ProductVariant {
            $variant = ProductVariant::query()->create([
                'product_id' => $product->getKey(),
                'sku' => ! empty($data['sku'])
                    ? (string) $data['sku']
                    : ProductVariant::generateSku($product->sku, $this->combinationSuffix($valueIds)),
                'price' => isset($data['price']) ? (int) $data['price'] : null,
                'discount_price' => isset($data['discount_price']) ? (int) $data['discount_price'] : null,
                'cost_price' => isset($data['cost_price']) ? (int) $data['cost_price'] : null,

                // Posted as a movement below, like every other stock figure.
                'stock' => 0,

                'low_stock_threshold' => $data['low_stock_threshold'] ?? $product->low_stock_threshold,
                'allow_backorder' => $data['allow_backorder'] ?? false,
                'weight' => $data['weight'] ?? null,
                'length' => $data['length'] ?? null,
                'width' => $data['width'] ?? null,
                'height' => $data['height'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'is_default' => $data['is_default'] ?? false,
                'sort_order' => $data['sort_order'] ?? (int) $product->variants()->max('sort_order') + 1,
            ]);

            $variant->attributeValues()->sync($valueIds);

            $this->storeImage($variant, $data);
            $this->refreshName($variant);

            // The first variant is the default: a variable product whose page
            // pre-selects nothing shows no price and no add-to-cart.
            if ($variant->is_default || $product->variants()->count() === 1) {
                $this->promoteToDefault($product, $variant);
            }

            $opening = (int) ($data['stock'] ?? 0);

            if ($opening !== 0) {
                $this->inventory->recordOpeningBalance($variant, $opening, $actor);
            }

            return $variant;
        });

        CatalogChanged::dispatch('product', $product->slug, $product->status->isVisible());

        return $variant->refresh()->load(['attributeValues.attribute', 'product']);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(ProductVariant $variant, array $data, ?Admin $actor = null): ProductVariant
    {
        $product = $variant->product;

        $updated = DB::transaction(function () use ($variant, $product, $data, $actor): ProductVariant {
            if (array_key_exists('attribute_value_ids', $data)) {
                /** @var array<int, int> $valueIds */
                $valueIds = array_map('intval', $data['attribute_value_ids']);

                $this->assertValuesAreCoherent($valueIds);
                $this->assertCombinationIsUnique($product, $valueIds, (int) $variant->getKey());

                $variant->attributeValues()->sync($valueIds);
                $variant->load(['attributeValues.attribute', 'product']);
            }

            $variant->fill(array_filter([
                'sku' => $data['sku'] ?? null,
                'low_stock_threshold' => $data['low_stock_threshold'] ?? null,
                'sort_order' => $data['sort_order'] ?? null,
            ], static fn (mixed $value): bool => $value !== null));

            // Assigned outside array_filter so null (inherit from the product)
            // and false remain expressible.
            foreach (['price', 'discount_price', 'cost_price', 'weight', 'length', 'width', 'height'] as $nullable) {
                if (array_key_exists($nullable, $data)) {
                    $variant->{$nullable} = $data[$nullable] === null ? null : (int) $data[$nullable];
                }
            }

            foreach (['is_active', 'allow_backorder'] as $flag) {
                if (array_key_exists($flag, $data)) {
                    $variant->{$flag} = (bool) $data[$flag];
                }
            }

            $this->storeImage($variant, $data);
            $this->refreshName($variant);

            $variant->save();

            if (! empty($data['is_default'])) {
                $this->promoteToDefault($product, $variant);
            }

            if (array_key_exists('stock', $data)) {
                $target = (int) $data['stock'];

                if ($target !== (int) $variant->stock) {
                    $this->inventory->setLevel(
                        $variant,
                        $target,
                        \App\Enums\StockMovementReason::ManualEdit,
                        $actor,
                        'Set from the variant edit form.',
                    );
                }
            }

            // Deactivating a variant removes its units from what the product
            // can sell, so the parent's roll-up has to be recomputed.
            if (array_key_exists('is_active', $data)) {
                $this->inventory->syncProductStock((int) $variant->product_id);
            }

            return $variant;
        });

        if ($product !== null) {
            CatalogChanged::dispatch('product', $product->slug, $product->status->isVisible());
        }

        return $updated->refresh()->load(['attributeValues.attribute', 'product']);
    }

    /**
     * @throws ValidationException
     */
    public function delete(ProductVariant $variant): void
    {
        $product = $variant->product;

        if ($product !== null && $product->variants()->count() === 1) {
            throw ValidationException::withMessages([
                'variant' => ['A variable product must keep at least one variant. Change the product type instead.'],
            ]);
        }

        $wasDefault = $variant->is_default;

        DB::transaction(function () use ($variant, $product, $wasDefault): void {
            $this->media->delete($variant->image);

            // Soft delete: the stock ledger and future order lines reference
            // this row, and the history must survive the variant's withdrawal.
            $variant->delete();

            if ($product !== null) {
                // Never leave a product with no default — its page would have
                // nothing pre-selected.
                if ($wasDefault) {
                    $next = $product->variants()->orderBy('sort_order')->first();

                    $next?->forceFill(['is_default' => true])->save();
                }

                $this->inventory->syncProductStock((int) $product->getKey());
            }
        });

        if ($product !== null) {
            CatalogChanged::dispatch('product', $product->slug, $product->status->isVisible());
        }
    }

    /**
     * Build every combination of the supplied attribute values at once.
     *
     * Generating a 4x3 matrix by hand is twelve trips through a form, so this
     * takes the cartesian product and skips combinations that already exist —
     * which makes it safe to re-run after adding a new colour.
     *
     * @param  array<int, array<int, int>>  $valueGroups  Value ids grouped by attribute.
     * @param  array<string, mixed>  $defaults  Applied to every generated variant.
     * @return array<int, ProductVariant>
     *
     * @throws ValidationException
     */
    public function generateMatrix(Product $product, array $valueGroups, array $defaults = [], ?Admin $actor = null): array
    {
        if (! $product->type->requiresVariants()) {
            throw ValidationException::withMessages([
                'product' => ['Only a variable product can have variants.'],
            ]);
        }

        $groups = array_values(array_filter($valueGroups, static fn (array $group): bool => $group !== []));

        if ($groups === []) {
            throw ValidationException::withMessages([
                'attributes' => ['Select at least one attribute value to generate variants from.'],
            ]);
        }

        $combinations = $this->cartesianProduct($groups);

        // A guard against a runaway matrix: five attributes with five values
        // each is 3,125 variants, which is a mis-click, not an intention.
        $limit = (int) config('catalog.max_generated_variants', 200);

        if (count($combinations) > $limit) {
            throw ValidationException::withMessages([
                'attributes' => [sprintf(
                    'That selection would create %d variants, above the limit of %d. Narrow the selection.',
                    count($combinations),
                    $limit,
                )],
            ]);
        }

        $created = [];

        foreach ($combinations as $combination) {
            if ($this->combinationExists($product, $combination)) {
                continue;
            }

            $created[] = $this->create(
                $product,
                array_merge($defaults, ['attribute_value_ids' => $combination]),
                $actor,
            );
        }

        return $created;
    }

    /**
     * Cartesian product of the grouped value ids.
     *
     * @param  array<int, array<int, int>>  $groups
     * @return array<int, array<int, int>>
     */
    private function cartesianProduct(array $groups): array
    {
        $result = [[]];

        foreach ($groups as $group) {
            $next = [];

            foreach ($result as $prefix) {
                foreach ($group as $value) {
                    $next[] = [...$prefix, (int) $value];
                }
            }

            $result = $next;
        }

        return $result;
    }

    /**
     * Refuse a value set that names the same attribute twice.
     *
     * @param  array<int, int>  $valueIds
     *
     * @throws ValidationException
     */
    private function assertValuesAreCoherent(array $valueIds): void
    {
        if ($valueIds === []) {
            throw ValidationException::withMessages([
                'attribute_value_ids' => ['A variant must be defined by at least one attribute value.'],
            ]);
        }

        $values = AttributeValue::query()
            ->with('attribute:id,name')
            ->whereIn('id', $valueIds)
            ->get();

        if ($values->count() !== count(array_unique($valueIds))) {
            throw ValidationException::withMessages([
                'attribute_value_ids' => ['One or more selected attribute values do not exist.'],
            ]);
        }

        $byAttribute = $values->groupBy('attribute_id');

        foreach ($byAttribute as $group) {
            if ($group->count() > 1) {
                $name = $group->first()?->attribute?->name ?? 'attribute';

                throw ValidationException::withMessages([
                    'attribute_value_ids' => ["A variant can only have one {$name} value."],
                ]);
            }
        }
    }

    /**
     * Refuse a second variant meaning the same combination.
     *
     * @param  array<int, int>  $valueIds
     *
     * @throws ValidationException
     */
    private function assertCombinationIsUnique(?Product $product, array $valueIds, ?int $ignoreVariantId = null): void
    {
        if ($product === null) {
            return;
        }

        if ($this->combinationExists($product, $valueIds, $ignoreVariantId)) {
            throw ValidationException::withMessages([
                'attribute_value_ids' => ['A variant with this combination of options already exists.'],
            ]);
        }
    }

    /**
     * @param  array<int, int>  $valueIds
     */
    private function combinationExists(Product $product, array $valueIds, ?int $ignoreVariantId = null): bool
    {
        sort($valueIds);

        return $product->variants()
            ->when($ignoreVariantId !== null, fn ($query) => $query->whereKeyNot($ignoreVariantId))
            ->with('attributeValues:id')
            ->get()
            ->contains(function (ProductVariant $variant) use ($valueIds): bool {
                $existing = $variant->attributeValues->pluck('id')
                    ->map(static fn (int|string $id): int => (int) $id)
                    ->sort()
                    ->values()
                    ->all();

                return $existing === $valueIds;
            });
    }

    /**
     * Exactly one default per product.
     */
    private function promoteToDefault(?Product $product, ProductVariant $variant): void
    {
        if ($product === null) {
            return;
        }

        $product->variants()
            ->whereKeyNot($variant->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);

        if (! $variant->is_default) {
            $variant->forceFill(['is_default' => true])->save();
        }
    }

    /**
     * Refresh the denormalised display name from the current values.
     */
    private function refreshName(ProductVariant $variant): void
    {
        $variant->loadMissing('attributeValues.attribute');
        $variant->name = $variant->buildName();
    }

    /**
     * @param  array<int, int>  $valueIds
     */
    private function combinationSuffix(array $valueIds): string
    {
        $slugs = AttributeValue::query()
            ->whereIn('id', $valueIds)
            ->orderBy('attribute_id')
            ->pluck('slug')
            ->all();

        return implode('-', $slugs);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeImage(ProductVariant $variant, array $data): void
    {
        $image = $data['image'] ?? null;

        if ($image instanceof UploadedFile) {
            $variant->image = $this->media->replace($image, $variant->image, 'products');

            return;
        }

        if (array_key_exists('image', $data) && $data['image'] === null && $variant->image !== null) {
            $this->media->delete($variant->image);
            $variant->image = null;
        }
    }
}
