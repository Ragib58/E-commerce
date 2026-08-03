<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductStatus;
use App\Services\MediaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute as CastAttribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A manufacturer or label products are attributed to.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $logo
 * @property string|null $description
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property ProductStatus $status
 * @property int $sort_order
 * @property-read string|null $logo_url
 */
class Brand extends Model
{
    /** @use HasFactory<\Database\Factories\BrandFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'logo',
        'description',
        'meta_title',
        'meta_description',
        'status',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @param  Builder<Brand>  $query
     * @return Builder<Brand>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereIn('status', ProductStatus::visible());
    }

    /**
     * @param  Builder<Brand>  $query
     * @return Builder<Brand>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || trim($term) === '') {
            return $query;
        }

        $term = trim($term);

        return $query->where(function (Builder $inner) use ($term): void {
            $inner->where('name', 'like', "%{$term}%")
                ->orWhere('slug', 'like', "%{$term}%");
        });
    }

    /**
     * @return CastAttribute<string|null, never>
     */
    protected function logoUrl(): CastAttribute
    {
        return CastAttribute::make(
            get: fn (): ?string => app(MediaService::class)->url($this->logo),
        )->shouldCache();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public static function generateSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'brand';
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
}
