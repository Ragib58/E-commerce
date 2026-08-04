<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Read paths for the public storefront.
 *
 * Separate from ProductService because the concerns genuinely differ: this
 * class never writes, always constrains to published records, and is the only
 * place catalog caching lives. Mixing the two would mean every admin write
 * carried read-cache logic it does not need.
 *
 * Performance rules applied throughout:
 *   - Every listing eager-loads what its card renders. An N+1 across a 24-item
 *     grid is 25 queries where 3 would do.
 *   - Sorting goes through an allowlist mapped to indexed columns, so no
 *     request can trigger a filesort over the whole catalog.
 *   - Results are paginated. There is no endpoint that returns every product.
 */
final class CatalogService
{
    /**
     * Paginated, filtered product listing.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function products(array $filters = []): LengthAwarePaginator
    {
        $perPage = $this->resolvePerPage($filters['per_page'] ?? null);

        $query = Product::query()
            ->published()
            ->withListingRelations();

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters['sort'] ?? null);

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * A single published product with everything its page renders.
     *
     * Not cached: the detail page shows stock, and serving a cached "in stock"
     * for something that sold out minutes ago produces a failed checkout —
     * which costs far more than the query saved.
     */
    public function product(string $slug): ?Product
    {
        return Product::query()
            ->published()
            ->withDetailRelations()
            ->where('slug', $slug)
            ->first();
    }

    /**
     * Products related to the given one, for the "you may also like" rail.
     *
     * Same category first, falling back to the same brand: an empty rail looks
     * broken, and a product in a sparse category still deserves neighbours.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Product>
     */
    public function relatedProducts(Product $product, int $limit = 8): \Illuminate\Database\Eloquent\Collection
    {
        return Product::query()
            ->published()
            ->withListingRelations()
            ->whereKeyNot($product->getKey())
            ->where(function (Builder $query) use ($product): void {
                if ($product->category_id !== null) {
                    $query->where('category_id', $product->category_id);
                }

                if ($product->brand_id !== null) {
                    $query->orWhere('brand_id', $product->brand_id);
                }
            })
            ->inRandomOrder()
            ->limit($limit)
            ->get();
    }

    /**
     * A merchandising rail — featured, new arrivals, best sellers.
     *
     * Cached: these appear on the homepage, are identical for every visitor,
     * and change only when an admin edits the catalog.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Product>
     */
    public function rail(string $rail, int $limit = 12): \Illuminate\Database\Eloquent\Collection
    {
        $column = match ($rail) {
            'featured' => 'is_featured',
            'new_arrivals' => 'is_new_arrival',
            'best_sellers' => 'is_best_seller',
            default => null,
        };

        if ($column === null) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return $this->remember(
            "rail:{$rail}:{$limit}",
            fn (): \Illuminate\Database\Eloquent\Collection => Product::query()
                ->published()
                ->withListingRelations()
                ->where($column, true)
                ->orderByDesc('published_at')
                ->limit($limit)
                ->get(),
        );
    }

    /**
     * Published products by their public identifiers, in the order asked for.
     *
     * Serves the compare tray and the recently-viewed rail, both of which hold
     * a list of ids on the client and need the products behind them. One query
     * for the whole list rather than one per id: a compare tray of four would
     * otherwise be four round trips, and recently-viewed up to twenty.
     *
     * Order is restored from the requested list because `whereIn` returns rows
     * in whatever order the index yields — which would scramble a
     * recently-viewed rail whose entire meaning is its ordering.
     *
     * Deliberately uncached: these lists are per-visitor, so a shared cache
     * entry would be a cache of one, and the key space is unbounded.
     *
     * @param  array<int, string>  $identifiers  uuids or slugs
     * @return \Illuminate\Database\Eloquent\Collection<int, Product>
     */
    public function productsByIdentifiers(array $identifiers, int $limit = 24): \Illuminate\Database\Eloquent\Collection
    {
        $identifiers = array_slice(array_values(array_unique(array_filter($identifiers))), 0, $limit);

        if ($identifiers === []) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        $products = Product::query()
            ->published()
            ->withListingRelations()
            ->where(fn (Builder $query) => $query
                ->whereIn('uuid', $identifiers)
                ->orWhereIn('slug', $identifiers))
            ->get();

        // Keyed by both identifiers, since a caller may mix them.
        $byIdentifier = [];

        foreach ($products as $product) {
            $byIdentifier[$product->uuid] = $product;
            $byIdentifier[$product->slug] = $product;
        }

        $ordered = [];

        foreach ($identifiers as $identifier) {
            $product = $byIdentifier[$identifier] ?? null;

            // An id that no longer resolves — unpublished since it was viewed —
            // simply drops out rather than leaving a hole the client must handle.
            if ($product !== null && ! isset($ordered[$product->getKey()])) {
                $ordered[$product->getKey()] = $product;
            }
        }

        return new \Illuminate\Database\Eloquent\Collection(array_values($ordered));
    }

    /**
     * The published category tree, for storefront navigation.
     *
     * @return \Illuminate\Support\Collection<int, Category>
     */
    public function categoryTree(): \Illuminate\Support\Collection
    {
        return $this->remember(
            'category-tree',
            fn (): \Illuminate\Support\Collection => app(CategoryService::class)->tree(publishedOnly: true),
        );
    }

    public function category(string $slug): ?Category
    {
        return Category::query()
            ->published()
            ->where('slug', $slug)
            ->first();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Brand>
     */
    public function brands(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->remember(
            'brands',
            fn (): \Illuminate\Database\Eloquent\Collection => Brand::query()
                ->published()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        );
    }

    public function brand(string $slug): ?Brand
    {
        return Brand::query()->published()->where('slug', $slug)->first();
    }

    /**
     * Attribute values available as storefront filters.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Attribute>
     */
    public function filterableAttributes(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->remember(
            'filter-attributes',
            fn (): \Illuminate\Database\Eloquent\Collection => Attribute::query()
                ->filterable()
                ->with('values')
                ->orderBy('sort_order')
                ->get(),
        );
    }

    /**
     * The price range across published products, for a filter slider's bounds.
     *
     * @return array{min: int, max: int}
     */
    public function priceRange(?Category $category = null): array
    {
        $key = 'price-range:' . ($category?->getKey() ?? 'all');

        return $this->remember($key, function () use ($category): array {
            $query = Product::query()->published();

            if ($category !== null) {
                $query->inCategory($category);
            }

            /** @var object{min_price: int|null, max_price: int|null}|null $result */
            $result = $query
                ->selectRaw('MIN(price) as min_price, MAX(price) as max_price')
                ->first();

            return [
                'min' => (int) ($result->min_price ?? 0),
                'max' => (int) ($result->max_price ?? 0),
            ];
        });
    }

    /**
     * Apply storefront filters to a product query.
     *
     * @param  Builder<Product>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['search'])) {
            $query->search((string) $filters['search']);
        }

        if (! empty($filters['category'])) {
            $category = $filters['category'] instanceof Category
                ? $filters['category']
                : Category::query()->where('slug', $filters['category'])->first();

            if ($category !== null) {
                // Include descendants: clicking "Clothing" must show the shirts
                // filed under "Clothing > Shirts", not an empty page.
                $query->inCategory($category);
            } else {
                // An unknown category must not silently return the whole
                // catalog, which would read as "the filter did nothing".
                $query->whereRaw('1 = 0');
            }
        }

        if (! empty($filters['brand'])) {
            $brands = (array) $filters['brand'];

            $query->whereHas('brand', fn (Builder $inner) => $inner->whereIn('slug', $brands));
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        // Compared against `price`, the list price, so a filter's bounds match
        // the numbers on the slider rather than shifting with each discount.
        if (isset($filters['min_price']) && $filters['min_price'] !== null && $filters['min_price'] !== '') {
            $query->where('price', '>=', (int) $filters['min_price']);
        }

        if (isset($filters['max_price']) && $filters['max_price'] !== null && $filters['max_price'] !== '') {
            $query->where('price', '<=', (int) $filters['max_price']);
        }

        /*
         * Attribute facets, e.g. attributes[colour] = ['red', 'blue'].
         *
         * Values within one attribute are OR'd (red *or* blue), while separate
         * attributes are AND'd (red *and* large) — because a shopper widening a
         * colour choice expects more results, whereas adding a size expects
         * fewer. Each attribute gets its own whereHas to produce that.
         */
        if (! empty($filters['attributes']) && is_array($filters['attributes'])) {
            foreach ($filters['attributes'] as $attributeSlug => $values) {
                $values = array_filter((array) $values);

                if ($values === []) {
                    continue;
                }

                $query->whereHas(
                    'variants.attributeValues',
                    function (Builder $inner) use ($attributeSlug, $values): void {
                        $inner->whereIn('attribute_values.slug', $values)
                            ->whereHas(
                                'attribute',
                                fn (Builder $attr) => $attr->where('slug', $attributeSlug),
                            );
                    },
                );
            }
        }

        foreach (['featured' => 'is_featured', 'new_arrival' => 'is_new_arrival', 'best_seller' => 'is_best_seller'] as $key => $column) {
            if (array_key_exists($key, $filters) && $filters[$key] !== null && $filters[$key] !== '') {
                $query->where($column, filter_var($filters[$key], FILTER_VALIDATE_BOOL));
            }
        }

        if (! empty($filters['in_stock'])) {
            $query->where(function (Builder $inner): void {
                $inner->where('stock', '>', 0)
                    ->orWhere('allow_backorder', true)
                    ->orWhere('type', \App\Enums\ProductType::Digital->value);
            });
        }
    }

    /**
     * Apply an allowlisted sort.
     *
     * @param  Builder<Product>  $query
     */
    private function applySort(Builder $query, ?string $sort): void
    {
        /** @var array<string, array{column: string, direction: string}> $sorts */
        $sorts = config('catalog.listing.sorts', []);

        $key = $sort !== null && isset($sorts[$sort])
            ? $sort
            : (string) config('catalog.listing.default_sort', 'newest');

        $definition = $sorts[$key] ?? ['column' => 'published_at', 'direction' => 'desc'];

        $query->orderBy($definition['column'], $definition['direction']);

        // A deterministic tiebreaker. Without it, rows sharing a sort value can
        // be returned in a different order on each page request, so an item
        // silently appears twice — or never — as a shopper pages through.
        $query->orderBy('id', 'desc');
    }

    private function resolvePerPage(mixed $requested): int
    {
        $default = (int) config('catalog.listing.per_page', 24);
        $max = (int) config('catalog.listing.max_per_page', 100);

        $perPage = (int) ($requested ?? $default);

        if ($perPage < 1) {
            $perPage = $default;
        }

        return min($perPage, $max);
    }

    /**
     * Cache a catalog read under the shared tag, when the store supports tags.
     *
     * Falls through uncached on a non-taggable store rather than caching
     * untagged: an entry CatalogChanged cannot purge would serve stale product
     * data for the whole TTL with no way to clear it.
     *
     * @template TValue
     *
     * @param  \Closure(): TValue  $callback
     * @return TValue
     */
    private function remember(string $key, \Closure $callback): mixed
    {
        if (! config('catalog.cache.enabled', true)) {
            return $callback();
        }

        $store = Cache::getStore();

        if (! $store instanceof \Illuminate\Cache\TaggableStore) {
            return $callback();
        }

        return Cache::tags([(string) config('catalog.cache.tag', 'catalog')])
            ->remember(
                'catalog:' . $key,
                (int) config('catalog.cache.ttl', 600),
                $callback,
            );
    }
}
