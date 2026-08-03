<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductStatus;
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
 * A node in the product taxonomy, nestable to unlimited depth.
 *
 * Depth is unbounded, so the usual adjacency-list traps have to be closed
 * explicitly:
 *
 *   - Subtree reads use the materialised `path` (one indexed prefix scan)
 *     rather than recursing parent by parent.
 *   - `path` and `depth` are maintained here in a saved hook, so no caller can
 *     forget to update them. They are derived from `parent_id`, which remains
 *     the only authoritative statement of where a node sits.
 *   - A cycle (making a node its own ancestor) is refused by the service, since
 *     one would make the tree infinite and every traversal non-terminating.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $image
 * @property string|null $banner
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property ProductStatus $status
 * @property int $sort_order
 * @property string|null $path
 * @property int $depth
 * @property-read string|null $image_url
 * @property-read string|null $banner_url
 */
class Category extends Model
{
    /** @use HasFactory<\Database\Factories\CategoryFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'image',
        'banner',
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
            'depth' => 'integer',
        ];
    }

    /**
     * Keep the materialised ancestry in step with `parent_id`.
     *
     * Hooked on the model rather than left to the service because these columns
     * must never disagree with `parent_id` — a factory, a seeder, or a console
     * command that sets a parent directly still gets a correct path.
     *
     * A move rewrites the whole subtree: every descendant's path embeds this
     * node's, so re-parenting one node invalidates all of them.
     */
    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            $category->depth = $category->computeDepth();
        });

        static::saved(function (self $category): void {
            $expectedPath = $category->computePath();

            if ($category->path !== $expectedPath) {
                // saveQuietly: this is bookkeeping, not a domain change, and
                // re-entering the saved hook would recurse.
                $category->forceFill(['path' => $expectedPath])->saveQuietly();
            }

            if ($category->wasChanged('parent_id')) {
                $category->refreshDescendantPaths();
            }
        });
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Direct children only. Eager-load `children.children` to fetch fixed
     * levels; use {@see descendants()} for an arbitrarily deep subtree.
     *
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Every descendant at any depth, in one query.
     *
     * @return Builder<Category>
     */
    public function descendants(): Builder
    {
        return static::query()
            ->where('path', 'like', $this->subtreePath() . '%')
            ->whereKeyNot($this->getKey())
            ->orderBy('depth')
            ->orderBy('sort_order');
    }

    /**
     * This node and everything beneath it — the id set a product query filters
     * on when browsing a category "including subcategories".
     *
     * @return array<int, int>
     */
    public function subtreeIds(): array
    {
        return [
            (int) $this->getKey(),
            ...$this->descendants()->pluck('id')->map(static fn (int|string $id): int => (int) $id)->all(),
        ];
    }

    /**
     * Ancestors from root to immediate parent, for breadcrumbs.
     *
     * Reads the ids straight out of `path`, so a breadcrumb of any depth costs
     * one query instead of one per level.
     *
     * @return \Illuminate\Support\Collection<int, Category>
     */
    public function ancestors(): \Illuminate\Support\Collection
    {
        $ids = $this->ancestorIds();

        if ($ids === []) {
            return collect();
        }

        return static::query()
            ->whereIn('id', $ids)
            ->orderBy('depth')
            ->get();
    }

    /**
     * @return array<int, int>
     */
    public function ancestorIds(): array
    {
        $segments = array_filter(explode('/', (string) $this->path));

        return array_values(array_map(
            static fn (string $id): int => (int) $id,
            array_filter($segments, fn (string $id): bool => (int) $id !== (int) $this->getKey()),
        ));
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereIn('status', ProductStatus::visible());
    }

    /**
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * @param  Builder<Category>  $query
     * @return Builder<Category>
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
     * Absolute URL for the category image.
     *
     * Expanded at read time from a stored relative path, so moving buckets or
     * putting a CDN in front never requires rewriting rows.
     *
     * @return CastAttribute<string|null, never>
     */
    protected function imageUrl(): CastAttribute
    {
        return CastAttribute::make(
            get: fn (): ?string => app(MediaService::class)->url($this->image),
        )->shouldCache();
    }

    /**
     * @return CastAttribute<string|null, never>
     */
    protected function bannerUrl(): CastAttribute
    {
        return CastAttribute::make(
            get: fn (): ?string => app(MediaService::class)->url($this->banner),
        )->shouldCache();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Generate a slug unique across the table, suffixing on collision.
     */
    public static function generateSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'category';
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
     * The prefix every descendant's path starts with.
     */
    private function subtreePath(): string
    {
        return ($this->path ?? $this->computePath());
    }

    private function computePath(): string
    {
        $parentPath = $this->parent_id !== null
            ? (string) (static::query()->whereKey($this->parent_id)->value('path') ?? '/')
            : '/';

        return rtrim($parentPath, '/') . '/' . $this->getKey() . '/';
    }

    private function computeDepth(): int
    {
        if ($this->parent_id === null) {
            return 0;
        }

        $parentDepth = static::query()->whereKey($this->parent_id)->value('depth');

        return (int) $parentDepth + 1;
    }

    /**
     * Rewrite paths and depths beneath a moved node.
     *
     * Walks level by level so each child reads its parent's freshly written
     * path. Bounded by tree depth, not by node count, and only runs on the rare
     * re-parent — not on ordinary edits.
     */
    private function refreshDescendantPaths(): void
    {
        static::query()
            ->where('parent_id', $this->getKey())
            ->get()
            ->each(function (self $child): void {
                $child->forceFill([
                    'depth' => $this->depth + 1,
                    'path' => rtrim((string) $this->path, '/') . '/' . $child->getKey() . '/',
                ])->saveQuietly();

                $child->refreshDescendantPaths();
            });
    }
}
