<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductStatus;
use App\Events\CatalogChanged;
use App\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Category lifecycle and tree integrity.
 *
 * The invariants enforced here are the ones the schema cannot express:
 *
 *   - A category may not become its own ancestor. A cycle makes the tree
 *     infinite: breadcrumbs never terminate, and subtree queries recurse until
 *     something dies. The database will happily accept one.
 *   - A category with children or products may not be deleted outright. Doing
 *     so would orphan a subtree or silently uncategorise live inventory, so the
 *     caller must either move the contents or ask explicitly.
 */
final class CategoryService
{
    public function __construct(
        private readonly MediaService $media,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): Category
    {
        $category = DB::transaction(function () use ($data): Category {
            $parentId = $this->resolveParentId($data['parent_id'] ?? null);

            $category = Category::query()->create([
                'parent_id' => $parentId,
                'name' => $data['name'],
                'slug' => $this->resolveSlug($data),
                'description' => $data['description'] ?? null,
                'meta_title' => $data['meta_title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
                'status' => $data['status'] ?? ProductStatus::Draft->value,
                'sort_order' => $data['sort_order'] ?? $this->nextSortOrder($parentId),
            ]);

            $this->storeImages($category, $data);

            return $category;
        });

        CatalogChanged::dispatch('category', $category->slug, $category->status->isVisible());

        return $category->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Category $category, array $data): Category
    {
        $wasPublic = $category->status->isVisible();

        $updated = DB::transaction(function () use ($category, $data): Category {
            if (array_key_exists('parent_id', $data)) {
                $parentId = $this->resolveParentId($data['parent_id']);
                $this->assertNoCycle($category, $parentId);
                $category->parent_id = $parentId;
            }

            $category->fill(array_filter([
                'name' => $data['name'] ?? null,
                'description' => $data['description'] ?? null,
                'meta_title' => $data['meta_title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
                'status' => $data['status'] ?? null,
                'sort_order' => $data['sort_order'] ?? null,
            ], static fn (mixed $value): bool => $value !== null));

            // Only regenerate the slug when explicitly asked. Silently
            // reslugging on a rename would break every existing inbound link
            // and search result pointing at the old URL.
            if (! empty($data['slug'])) {
                $category->slug = Category::generateSlug((string) $data['slug'], (int) $category->getKey());
            }

            $category->save();

            $this->storeImages($category, $data);

            return $category;
        });

        CatalogChanged::dispatch(
            'category',
            $updated->slug,
            $updated->status->isVisible(),
            $wasPublic,
        );

        return $updated->refresh();
    }

    /**
     * Delete a category, optionally re-homing what it contains.
     *
     * @param  bool  $cascade  When true, children are re-parented to this
     *                         category's parent and products are uncategorised.
     *                         When false, a non-empty category is refused.
     *
     * @throws ValidationException
     */
    public function delete(Category $category, bool $cascade = false): void
    {
        $hasChildren = $category->children()->exists();
        $hasProducts = $category->products()->exists();

        if (! $cascade && ($hasChildren || $hasProducts)) {
            throw ValidationException::withMessages([
                'category' => [sprintf(
                    'This category still contains %s. Move them first, or confirm the cascade.',
                    $hasChildren && $hasProducts
                        ? 'subcategories and products'
                        : ($hasChildren ? 'subcategories' : 'products'),
                )],
            ]);
        }

        $slug = $category->slug;
        $wasPublic = $category->status->isVisible();

        DB::transaction(function () use ($category): void {
            /*
             * Lift children one level rather than deleting them. Deleting a
             * mid-level category is usually a restructure, not a decision to
             * destroy everything filed beneath it — and the destructive reading
             * is unrecoverable.
             */
            $category->children()->update(['parent_id' => $category->parent_id]);

            // Products survive uncategorised. They are saleable inventory with
            // order history; a taxonomy edit must not remove them from sale.
            $category->products()->update(['category_id' => null]);

            // Re-read: the child rows just moved, so the in-memory instance's
            // relations are stale and the FK guard would fire on a phantom.
            $category->refresh();

            $this->media->delete($category->image);
            $this->media->delete($category->banner);

            $category->delete();
        });

        CatalogChanged::dispatch('category', $slug, false, $wasPublic);
    }

    /**
     * The full category tree, ordered for display.
     *
     * Fetches every row in one query and assembles the hierarchy in memory.
     * The recursive-relation alternative issues one query per level, which is
     * unbounded here because nesting is.
     *
     * @return Collection<int, Category>
     */
    public function tree(bool $publishedOnly = false): Collection
    {
        $categories = Category::query()
            ->when($publishedOnly, fn ($query) => $query->published())
            ->orderBy('depth')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->assembleTree($categories);
    }

    /**
     * Attach children to parents in memory, returning the roots.
     *
     * @param  Collection<int, Category>  $categories
     * @return Collection<int, Category>
     */
    private function assembleTree(Collection $categories): Collection
    {
        $byParent = $categories->groupBy('parent_id');

        $categories->each(function (Category $category) use ($byParent): void {
            $children = $byParent->get((string) $category->getKey())
                ?? $byParent->get($category->getKey())
                ?? collect();

            // setRelation, so serialisation emits `children` without the
            // resource layer triggering a query per node.
            $category->setRelation('children', $children->values());
        });

        /*
         * A published-only tree can contain a node whose parent is a draft.
         * Keying on "has no parent" would drop that whole branch, so anything
         * whose parent is absent from the result set is promoted to a root.
         */
        $presentIds = $categories->pluck('id')->all();

        return $categories
            ->filter(fn (Category $category): bool => $category->parent_id === null
                || ! in_array($category->parent_id, $presentIds, strict: true))
            ->values();
    }

    /**
     * Reorder siblings in one pass.
     *
     * @param  array<int, array{id: int, sort_order: int, parent_id?: int|null}>  $items
     *
     * @throws ValidationException
     */
    public function reorder(array $items): void
    {
        DB::transaction(function () use ($items): void {
            foreach ($items as $item) {
                $category = Category::query()->find($item['id']);

                if ($category === null) {
                    continue;
                }

                if (array_key_exists('parent_id', $item)) {
                    $parentId = $this->resolveParentId($item['parent_id']);
                    $this->assertNoCycle($category, $parentId);
                    $category->parent_id = $parentId;
                }

                $category->sort_order = $item['sort_order'];
                $category->save();
            }
        });

        CatalogChanged::dispatch('category');
    }

    /**
     * Store or replace a category's image and banner.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeImages(Category $category, array $data): void
    {
        $changed = false;

        foreach (['image', 'banner'] as $field) {
            $file = $data[$field] ?? null;

            if ($file instanceof UploadedFile) {
                $category->{$field} = $this->media->replace($file, $category->{$field}, 'categories');
                $changed = true;

                continue;
            }

            // An explicit null clears the asset; an absent key leaves it alone.
            if (array_key_exists($field, $data) && $data[$field] === null && $category->{$field} !== null) {
                $this->media->delete($category->{$field});
                $category->{$field} = null;
                $changed = true;
            }
        }

        if ($changed) {
            $category->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveSlug(array $data): string
    {
        $source = ! empty($data['slug']) ? (string) $data['slug'] : (string) $data['name'];

        return Category::generateSlug($source);
    }

    /**
     * @throws ValidationException
     */
    private function resolveParentId(mixed $parentId): ?int
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }

        $parentId = (int) $parentId;

        if (! Category::query()->whereKey($parentId)->exists()) {
            throw ValidationException::withMessages([
                'parent_id' => ['The selected parent category does not exist.'],
            ]);
        }

        return $parentId;
    }

    /**
     * Refuse a move that would make a category its own ancestor.
     *
     * Checks the *proposed* parent's ancestry rather than the current one:
     * moving A under its own descendant D is exactly the case that creates a
     * cycle, and D's path still contains A at the moment of the check.
     *
     * @throws ValidationException
     */
    private function assertNoCycle(Category $category, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($parentId === (int) $category->getKey()) {
            throw ValidationException::withMessages([
                'parent_id' => ['A category cannot be its own parent.'],
            ]);
        }

        $parent = Category::query()->find($parentId);

        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_id' => ['The selected parent category does not exist.'],
            ]);
        }

        if (in_array((int) $category->getKey(), $parent->ancestorIds(), strict: true)) {
            throw ValidationException::withMessages([
                'parent_id' => ['A category cannot be moved beneath one of its own subcategories.'],
            ]);
        }
    }

    private function nextSortOrder(?int $parentId): int
    {
        return (int) Category::query()
            ->where('parent_id', $parentId)
            ->max('sort_order') + 1;
    }
}
