<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\MediaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute as CastAttribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A sellable variation of a variable product — "Medium / Red".
 *
 * Price and weight fall back to the parent product when null. That inheritance
 * is the point: most variable products price uniformly, so a price change is
 * one write to the parent rather than N writes that can partially fail and
 * leave variants disagreeing.
 *
 * @property int $id
 * @property string $uuid
 * @property int $product_id
 * @property string $sku
 * @property string|null $name
 * @property int|null $price
 * @property int|null $discount_price
 * @property int $stock
 * @property int $low_stock_threshold
 * @property bool $allow_backorder
 * @property string|null $image
 * @property int|null $weight
 * @property bool $is_active
 * @property bool $is_default
 * @property int $sort_order
 * @property-read int $effective_price
 */
class ProductVariant extends Model
{
    /** @use HasFactory<\Database\Factories\ProductVariantFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'sku',
        'name',
        'price',
        'discount_price',
        'cost_price',
        'stock',
        'low_stock_threshold',
        'allow_backorder',
        'image',
        'weight',
        'length',
        'width',
        'height',
        'is_active',
        'is_default',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'discount_price' => 'integer',
            'cost_price' => 'integer',
            'stock' => 'integer',
            'low_stock_threshold' => 'integer',
            'allow_backorder' => 'boolean',
            'weight' => 'integer',
            'length' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $variant): void {
            $variant->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The attribute values that define this combination.
     *
     * @return BelongsToMany<AttributeValue, $this>
     */
    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(
            AttributeValue::class,
            'attribute_value_variant',
            'product_variant_id',
            'attribute_value_id',
        );
    }

    /**
     * @return HasMany<ProductMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class);
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latest('created_at');
    }

    /**
     * Selling price, inheriting from the parent product when unset.
     *
     * @return CastAttribute<int, never>
     */
    protected function effectivePrice(): CastAttribute
    {
        return CastAttribute::make(
            get: function (): int {
                if ($this->discount_price !== null && $this->discount_price > 0) {
                    return $this->discount_price;
                }

                if ($this->price !== null) {
                    return $this->price;
                }

                return (int) ($this->parentProduct()?->effective_price ?? 0);
            },
        );
    }

    /**
     * List price before any discount, inheriting from the parent when unset.
     *
     * @return CastAttribute<int, never>
     */
    protected function basePrice(): CastAttribute
    {
        return CastAttribute::make(
            get: fn (): int => $this->price ?? (int) ($this->parentProduct()?->price ?? 0),
        );
    }

    /**
     * @return CastAttribute<int|null, never>
     */
    protected function effectiveWeight(): CastAttribute
    {
        return CastAttribute::make(
            get: fn (): ?int => $this->weight ?? $this->parentProduct()?->weight,
        );
    }

    /**
     * The parent product, but only if it is already in memory.
     *
     * Price and weight inherit from the product, so these accessors would each
     * reach for the relation — and under `Model::shouldBeStrict()` an
     * unloaded relation is a LazyLoadingViolationException, turning a missed
     * eager-load into a 500 rather than a silent N+1.
     *
     * Returning null instead lets the accessors fall back to their own values.
     * The relation is eager-loaded wherever variants are serialised, so this
     * degrades a rendering bug into a conservative default rather than an
     * outage. Callers that need the inherited figure must load `product`.
     */
    private function parentProduct(): ?Product
    {
        return $this->relationLoaded('product') ? $this->product : null;
    }

    /**
     * @return CastAttribute<bool, never>
     */
    protected function isInStock(): CastAttribute
    {
        return CastAttribute::make(
            get: fn (): bool => $this->allow_backorder || $this->stock > 0,
        );
    }

    /**
     * @return CastAttribute<bool, never>
     */
    protected function isLowStock(): CastAttribute
    {
        return CastAttribute::make(
            get: fn (): bool => $this->stock > 0 && $this->stock <= $this->low_stock_threshold,
        );
    }

    /**
     * @return CastAttribute<string|null, never>
     */
    protected function imageUrl(): CastAttribute
    {
        return CastAttribute::make(
            get: fn (): ?string => app(MediaService::class)->url($this->image),
        )->shouldCache();
    }

    /**
     * Human-readable combination label, e.g. "Medium / Red".
     *
     * Falls back to the stored `name` when the relation is not loaded, so a
     * list view does not trigger a query per row.
     */
    public function buildName(): string
    {
        if (! $this->relationLoaded('attributeValues')) {
            return (string) ($this->name ?? $this->sku);
        }

        $parts = $this->attributeValues
            ->sortBy(fn (AttributeValue $value): int => $value->attribute?->sort_order ?? 0)
            ->pluck('value')
            ->all();

        return $parts === [] ? (string) ($this->name ?? $this->sku) : implode(' / ', $parts);
    }

    /**
     * @param  Builder<ProductVariant>  $query
     * @return Builder<ProductVariant>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<ProductVariant>  $query
     * @return Builder<ProductVariant>
     */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query
            ->whereColumn('stock', '<=', 'low_stock_threshold')
            ->where('stock', '>', 0);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Derive a unique SKU from the parent's, suffixed with the combination.
     */
    public static function generateSku(string $productSku, string $suffix): string
    {
        $base = strtoupper($productSku . '-' . (Str::slug($suffix) ?: Str::random(4)));
        $base = str_replace('-', '-', $base);

        $candidate = $base;
        $counter = 2;

        while (static::withTrashed()->where('sku', $candidate)->exists()) {
            $candidate = "{$base}-{$counter}";
            $counter++;
        }

        return $candidate;
    }
}
