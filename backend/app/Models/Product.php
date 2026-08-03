<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Services\MediaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute as CastAttribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A catalog product.
 *
 * Money handling: `price`, `discount_price`, and `cost_price` are integer minor
 * units (cents). Nothing in this class converts them to floats — the API layer
 * formats for display, and arithmetic stays in integers so it stays exact.
 *
 * Stock handling depends on {@see ProductType}. For a variable product the
 * `stock` column is a cached roll-up of its variants and must never be written
 * directly; InventoryService owns it. `effective_stock` is the accessor that
 * gives the right answer regardless of type.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string $sku
 * @property string|null $short_description
 * @property string|null $description
 * @property int|null $category_id
 * @property int|null $brand_id
 * @property ProductType $type
 * @property int $price
 * @property int|null $discount_price
 * @property int|null $cost_price
 * @property float|null $tax_rate
 * @property bool $is_taxable
 * @property int $stock
 * @property int $low_stock_threshold
 * @property bool $allow_backorder
 * @property int|null $weight
 * @property ProductStatus $status
 * @property bool $is_featured
 * @property bool $is_new_arrival
 * @property bool $is_best_seller
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property-read int $effective_price
 * @property-read int $effective_stock
 * @property-read bool $is_in_stock
 * @property-read bool $is_low_stock
 */
class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'sku',
        'short_description',
        'description',
        'category_id',
        'brand_id',
        'type',
        'price',
        'discount_price',
        'cost_price',
        'tax_rate',
        'is_taxable',
        'stock',
        'low_stock_threshold',
        'allow_backorder',
        'weight',
        'length',
        'width',
        'height',
        'status',
        'is_featured',
        'is_new_arrival',
        'is_best_seller',
        'meta_title',
        'meta_description',
        'og_image',
        'video_url',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'status' => ProductStatus::class,
            'price' => 'integer',
            'discount_price' => 'integer',
            'cost_price' => 'integer',
            'tax_rate' => 'float',
            'is_taxable' => 'boolean',
            'stock' => 'integer',
            'low_stock_threshold' => 'integer',
            'allow_backorder' => 'boolean',
            'weight' => 'integer',
            'length' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'is_featured' => 'boolean',
            'is_new_arrival' => 'boolean',
            'is_best_seller' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $product): void {
            $product->uuid ??= (string) Str::uuid();
        });

        /*
         * Stamp the moment a product first goes live.
         *
         * Kept distinct from created_at because "new arrivals" means recently
         * *on sale*, not recently drafted — a product typed in months ago and
         * published today is a new arrival.
         */
        static::saving(function (self $product): void {
            if ($product->status === ProductStatus::Published && $product->published_at === null) {
                $product->published_at = now();
            }
        });
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order');
    }

    /**
     * Only the variants a shopper may actually buy.
     *
     * @return HasMany<ProductVariant, $this>
     */
    public function activeVariants(): HasMany
    {
        return $this->variants()->where('is_active', true);
    }

    /**
     * @return HasMany<ProductMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<ProductMedia, $this>
     */
    public function images(): HasMany
    {
        return $this->media()->where('type', 'image');
    }

    /**
     * @return HasMany<ProductMedia, $this>
     */
    public function thumbnail(): HasMany
    {
        return $this->media()->where('is_thumbnail', true);
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latest('created_at');
    }

    /**
     * The price a shopper actually pays, before tax.
     *
     * A discount of zero is treated as "no discount": a free product is
     * expressed by setting `price` to 0, not by discounting to 0, and reading a
     * stray 0 as the selling price would give the catalog away.
     *
     * @return CastAttribute<int, never>
     */
    protected function effectivePrice(): CastAttribute
    {
        return CastAttribute::make(
            get: fn (): int => $this->discount_price !== null && $this->discount_price > 0
                ? $this->discount_price
                : $this->price,
        );
    }

    /**
     * @return CastAttribute<bool, never>
     */
    protected function isOnSale(): CastAttribute
    {
        return CastAttribute::make(
            get: fn (): bool => $this->discount_price !== null
                && $this->discount_price > 0
                && $this->discount_price < $this->price,
        );
    }

    /**
     * Percentage off, rounded, for a "-25%" badge.
     *
     * @return CastAttribute<int|null, never>
     */
    protected function discountPercentage(): CastAttribute
    {
        return CastAttribute::make(
            get: function (): ?int {
                if (! $this->is_on_sale || $this->price <= 0) {
                    return null;
                }

                return (int) round((($this->price - $this->discount_price) / $this->price) * 100);
            },
        );
    }

    /**
     * Stock from whichever row owns it.
     *
     * For a variable product the authoritative figure is the sum of its
     * variants; the `stock` column is only a cache of that sum. Reading the
     * loaded relation when present avoids a query in list contexts that
     * already eager-loaded variants.
     *
     * @return CastAttribute<int, never>
     */
    protected function effectiveStock(): CastAttribute
    {
        return CastAttribute::make(
            get: function (): int {
                if (! $this->type->usesVariantStock()) {
                    return $this->stock;
                }

                if ($this->relationLoaded('variants')) {
                    return (int) $this->variants
                        ->where('is_active', true)
                        ->sum('stock');
                }

                return (int) $this->variants()->where('is_active', true)->sum('stock');
            },
        );
    }

    /**
     * @return CastAttribute<bool, never>
     */
    protected function isInStock(): CastAttribute
    {
        return CastAttribute::make(
            get: function (): bool {
                // Untracked inventory (digital goods) never sells out.
                if (! $this->type->tracksInventory()) {
                    return true;
                }

                if ($this->allow_backorder) {
                    return true;
                }

                return $this->effective_stock > 0;
            },
        );
    }

    /**
     * At or below the reorder point, but not yet empty.
     *
     * Excludes zero deliberately: an out-of-stock product is a different alert
     * with a different urgency, and folding the two together buries it.
     *
     * @return CastAttribute<bool, never>
     */
    protected function isLowStock(): CastAttribute
    {
        return CastAttribute::make(
            get: function (): bool {
                if (! $this->type->tracksInventory()) {
                    return false;
                }

                $stock = $this->effective_stock;

                return $stock > 0 && $stock <= $this->low_stock_threshold;
            },
        );
    }

    /**
     * @return CastAttribute<string|null, never>
     */
    protected function ogImageUrl(): CastAttribute
    {
        return CastAttribute::make(
            get: fn (): ?string => app(MediaService::class)->url($this->og_image),
        )->shouldCache();
    }

    /**
     * Live products only.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereIn('status', ProductStatus::visible());
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || trim($term) === '') {
            return $query;
        }

        $term = trim($term);

        return $query->where(function (Builder $inner) use ($term): void {
            $inner->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('slug', 'like', "%{$term}%")
                ->orWhere('short_description', 'like', "%{$term}%");
        });
    }

    /**
     * Products in a category, optionally including its whole subtree.
     *
     * Subtree browsing is the default a shopper expects: clicking "Clothing"
     * should show the shirts filed under "Clothing > Shirts", not an empty page.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeInCategory(Builder $query, Category $category, bool $includeDescendants = true): Builder
    {
        if (! $includeDescendants) {
            return $query->where('category_id', $category->getKey());
        }

        return $query->whereIn('category_id', $category->subtreeIds());
    }

    /**
     * Products at or below their reorder point.
     *
     * The column-to-column comparison cannot use an index, so the status filter
     * runs first to keep the scan to live rows.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query
            ->whereNot('type', ProductType::Digital->value)
            ->whereColumn('stock', '<=', 'low_stock_threshold')
            ->where('stock', '>', 0);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeOutOfStock(Builder $query): Builder
    {
        return $query
            ->whereNot('type', ProductType::Digital->value)
            ->where('stock', '<=', 0);
    }

    /**
     * Eager-load everything a listing card renders.
     *
     * Named so call sites read as intent rather than as a relation list, and so
     * adding a field to the card is one change here instead of one per query.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeWithListingRelations(Builder $query): Builder
    {
        return $query->with([
            /*
             * Whole models, not a column subset.
             *
             * Selecting `id,name,slug` here saves a few bytes per row and costs
             * a 500 the moment any resource renders a field outside that list —
             * `Model::shouldBeStrict()` turns the missing attribute into an
             * exception rather than a silent null. The categories and brands
             * tables are small and narrow; the saving was never worth the
             * coupling between this scope and every resource downstream.
             */
            'category',
            'brand',

            // The thumbnail alone: a 24-card grid does not need every gallery
            // image, and that difference is worth the constraint.
            'media' => fn ($media) => $media->where('is_thumbnail', true)->limit(1),
        ]);
    }

    /**
     * Everything the product detail page renders, in one round of queries.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeWithDetailRelations(Builder $query): Builder
    {
        return $query->with([
            'category',
            'brand',
            'media',
            'activeVariants.attributeValues.attribute',

            /*
             * The variants' own parent. Variant price and weight inherit from
             * the product when unset, so serialising a variant reaches for it;
             * without this the accessor sees an unloaded relation and falls
             * back to zero. Eloquent serves this from the identity map rather
             * than issuing another query.
             */
            'activeVariants.product',
        ]);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public static function generateSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        $suffix = 2;

        while (
            static::withTrashed()
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * Derive a unique SKU when the operator did not supply one.
     */
    public static function generateSku(string $name): string
    {
        $base = strtoupper(Str::slug(Str::limit($name, 12, '')) ?: 'SKU');
        $base = str_replace('-', '', $base);

        do {
            $candidate = $base . '-' . strtoupper(Str::random(6));
        } while (static::withTrashed()->where('sku', $candidate)->exists());

        return $candidate;
    }
}
