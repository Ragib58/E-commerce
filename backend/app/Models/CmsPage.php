<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PublishStatus;
use App\Services\MediaService;
use App\Traits\Schedulable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute as CastAttribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An editorial page — About, Contact, a policy, or anything an operator adds.
 *
 * Resolved by slug from the storefront, so the URL is the identifier and the
 * integer id never appears in a public route.
 *
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $excerpt
 * @property string|null $content
 * @property string|null $featured_image
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property string|null $seo_keywords
 * @property string|null $og_image
 * @property bool $is_indexable
 * @property PublishStatus $status
 * @property bool $is_system
 * @property int $sort_order
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $published_at
 * @property-read string|null $featured_image_url
 */
class CmsPage extends Model
{
    /** @use HasFactory<\Database\Factories\CmsPageFactory> */
    use HasFactory;
    use Schedulable;
    use SoftDeletes;

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'content',
        'featured_image',
        'seo_title',
        'seo_description',
        'seo_keywords',
        'og_image',
        'is_indexable',
        'status',
        'sort_order',
        'starts_at',
        'ends_at',
        'published_at',
    ];

    /**
     * `is_system` is deliberately absent from $fillable.
     *
     * It is a delete guard on the seeded legal pages, so a request body that
     * happened to carry `is_system: false` must not be able to clear it. Only
     * the seeder sets it, via forceFill.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PublishStatus::class,
            'is_indexable' => 'boolean',
            'is_system' => 'boolean',
            'sort_order' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Pages a visitor may read right now.
     *
     * @param  Builder<CmsPage>  $query
     * @return Builder<CmsPage>
     */
    public function scopeLive(Builder $query, ?Carbon $at = null): Builder
    {
        return $query
            ->whereIn('status', PublishStatus::publishable())
            ->withinWindow($at);
    }

    /**
     * @param  Builder<CmsPage>  $query
     * @return Builder<CmsPage>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || trim($term) === '') {
            return $query;
        }

        $term = trim($term);

        return $query->where(function (Builder $inner) use ($term): void {
            $inner->where('title', 'like', "%{$term}%")
                ->orWhere('slug', 'like', "%{$term}%");
        });
    }

    /**
     * @return CastAttribute<string|null, never>
     */
    protected function featuredImageUrl(): CastAttribute
    {
        return CastAttribute::make(
            get: fn (): ?string => app(MediaService::class)->url($this->featured_image),
        )->shouldCache();
    }

    /**
     * @return CastAttribute<string|null, never>
     */
    protected function ogImageUrl(): CastAttribute
    {
        return CastAttribute::make(
            // Falls back to the featured image: a page with a hero but no
            // explicit social image should still produce a rich card.
            get: fn (): ?string => app(MediaService::class)->url($this->og_image ?? $this->featured_image),
        )->shouldCache();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Generate a slug unique across live and trashed rows.
     *
     * Trashed rows are included because a soft-deleted page still occupies its
     * slug in the unique index — restoring it after a replacement took the same
     * slug would otherwise fail at the database level.
     */
    public static function generateSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'page';
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
