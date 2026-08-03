<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\MediaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute as CastAttribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One gallery entry — an image or a video — belonging to a product.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property string $type
 * @property string $path
 * @property string|null $alt_text
 * @property bool $is_thumbnail
 * @property int $sort_order
 * @property-read string|null $url
 */
class ProductMedia extends Model
{
    /** @use HasFactory<\Database\Factories\ProductMediaFactory> */
    use HasFactory;

    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    /**
     * Laravel would pluralise this to `product_medias`.
     */
    protected $table = 'product_media';

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'type',
        'path',
        'alt_text',
        'is_thumbnail',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_thumbnail' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Absolute URL for the asset.
     *
     * Video rows store an external URL rather than a stored file, and
     * MediaService passes absolute URLs through untouched.
     *
     * @return CastAttribute<string|null, never>
     */
    protected function url(): CastAttribute
    {
        return CastAttribute::make(
            get: fn (): ?string => app(MediaService::class)->url($this->path),
        )->shouldCache();
    }

    public function isVideo(): bool
    {
        return $this->type === self::TYPE_VIDEO;
    }

    /**
     * @param  Builder<ProductMedia>  $query
     * @return Builder<ProductMedia>
     */
    public function scopeImages(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_IMAGE);
    }
}
