<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PublishStatus;
use App\Events\ContentChanged;
use App\Models\CmsPage;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CMS page lifecycle.
 *
 * Two invariants the schema cannot express live here:
 *
 *   - **Content is sanitised on write.** The stored value is the safe value,
 *     so no read path can bypass the filter. See HtmlSanitiser for why an
 *     admin-only authoring surface still needs one.
 *   - **System pages cannot be deleted.** The seeded legal pages are fully
 *     editable, but a footer link to a missing privacy policy is a compliance
 *     failure rather than a cosmetic one, so deletion is refused and the
 *     operator is pointed at unpublishing instead.
 */
final class CmsPageService
{
    public function __construct(
        private readonly MediaService $media,
        private readonly HtmlSanitiser $sanitiser,
    ) {
    }

    /**
     * A published page by slug, for the storefront.
     *
     * Cached: a privacy policy is identical for every visitor and changes a
     * handful of times a year.
     *
     * A miss is deliberately *not* cached. `remember()` treats a null result as
     * "not cached" and re-runs the closure, which is the behaviour we want: the
     * lookup is a single indexed equality on a unique column, and caching
     * negatives would let an attacker fill the tag with entries for slugs that
     * do not exist — evicting the handful of real pages that do.
     */
    public function published(string $slug): ?CmsPage
    {
        if (! $this->cacheEnabled()) {
            return $this->fetchPublished($slug);
        }

        return Cache::tags([$this->cacheTag()])->remember(
            "content:page:{$slug}",
            (int) config('content.cache.ttl', 600),
            fn (): ?CmsPage => $this->fetchPublished($slug),
        );
    }

    private function fetchPublished(string $slug): ?CmsPage
    {
        return CmsPage::query()->live()->where('slug', $slug)->first();
    }

    /**
     * Published pages, for footer navigation.
     *
     * Returns only what a link needs — title and slug — rather than the full
     * body of every policy page, which would otherwise be fetched on every
     * page render to build a footer.
     *
     * @return EloquentCollection<int, CmsPage>
     */
    public function publishedIndex(): EloquentCollection
    {
        $fetch = fn (): EloquentCollection => CmsPage::query()
            ->live()
            /*
             * Every column CmsPageResource reads unconditionally.
             *
             * The body, images, and SEO fields are deliberately absent — the
             * resource emits those only when they were loaded. But anything it
             * reads *without* guarding must be listed here: `Model::shouldBeStrict`
             * is enabled outside production, so touching an unselected
             * attribute throws MissingAttributeException rather than
             * silently returning null.
             */
            ->select(['id', 'title', 'slug', 'excerpt', 'sort_order', 'published_at', 'updated_at'])
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        if (! $this->cacheEnabled()) {
            return $fetch();
        }

        return Cache::tags([$this->cacheTag()])->remember(
            'content:pages:index',
            (int) config('content.cache.ttl', 600),
            $fetch,
        );
    }

    /**
     * Every page, filtered, for the admin list.
     *
     * @param  array<string, mixed>  $filters
     * @return EloquentCollection<int, CmsPage>
     */
    public function all(array $filters = []): EloquentCollection
    {
        return CmsPage::query()
            ->search($filters['search'] ?? null)
            ->when(
                ! empty($filters['status']),
                fn ($query) => $query->where('status', (string) $filters['status']),
            )
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): CmsPage
    {
        $page = DB::transaction(function () use ($data): CmsPage {
            $status = $data['status'] ?? PublishStatus::Draft->value;

            $page = CmsPage::query()->create([
                'title' => $data['title'],
                'slug' => CmsPage::generateSlug(
                    ! empty($data['slug']) ? (string) $data['slug'] : (string) $data['title'],
                ),
                'excerpt' => $this->resolveExcerpt($data),
                'content' => $this->sanitiser->sanitise($data['content'] ?? null),
                'seo_title' => $data['seo_title'] ?? null,
                'seo_description' => $this->resolveSeoDescription($data),
                'seo_keywords' => $data['seo_keywords'] ?? null,
                'is_indexable' => $data['is_indexable'] ?? true,
                'status' => $status,
                'sort_order' => $data['sort_order'] ?? $this->nextSortOrder(),
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'published_at' => $this->resolvePublishedAt($status, null),
            ]);

            $this->storeImages($page, $data);

            return $page;
        });

        ContentChanged::dispatch('page', $page->slug, $this->isLive($page));

        return $page->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CmsPage $page, array $data): CmsPage
    {
        $wasLive = $this->isLive($page);
        $previousSlug = $page->slug;

        DB::transaction(function () use ($page, $data): void {
            foreach (['title', 'seo_title', 'seo_keywords'] as $field) {
                if (array_key_exists($field, $data)) {
                    $page->{$field} = $data[$field];
                }
            }

            /*
             * The slug is regenerated only when explicitly supplied.
             *
             * Reslugging on a rename would break every inbound link, bookmark,
             * and search result pointing at the old URL — and for a policy page
             * those links are often in printed terms and past order emails.
             */
            if (! empty($data['slug'])) {
                $page->slug = CmsPage::generateSlug((string) $data['slug'], (int) $page->getKey());
            }

            if (array_key_exists('content', $data)) {
                $page->content = $this->sanitiser->sanitise($data['content']);
            }

            if (array_key_exists('excerpt', $data)) {
                $page->excerpt = $data['excerpt'] !== null
                    ? $this->sanitiser->toPlainText((string) $data['excerpt'], 300)
                    : null;
            }

            if (array_key_exists('seo_description', $data)) {
                $page->seo_description = $data['seo_description'] !== null
                    ? $this->sanitiser->toPlainText((string) $data['seo_description'], 300)
                    : null;
            }

            if (array_key_exists('is_indexable', $data)) {
                $page->is_indexable = (bool) $data['is_indexable'];
            }

            if (array_key_exists('sort_order', $data)) {
                $page->sort_order = (int) $data['sort_order'];
            }

            foreach (['starts_at', 'ends_at'] as $field) {
                if (array_key_exists($field, $data)) {
                    $page->{$field} = $data[$field];
                }
            }

            if (array_key_exists('status', $data)) {
                $page->published_at = $this->resolvePublishedAt(
                    (string) $data['status'],
                    $page->published_at,
                );
                $page->status = $data['status'];
            }

            $page->save();

            $this->storeImages($page, $data);
        });

        $page->refresh();

        ContentChanged::dispatch('page', $page->slug, $this->isLive($page), $wasLive);

        // A slug change retires the old URL as well as publishing the new one;
        // the storefront must stop serving a page at an address that no longer
        // resolves.
        if ($previousSlug !== $page->slug) {
            ContentChanged::dispatch('page', $previousSlug, false, $wasLive);
        }

        return $page;
    }

    /**
     * Delete a page, unless it is one of the seeded system pages.
     *
     * @throws ValidationException
     */
    public function delete(CmsPage $page): void
    {
        if ($page->is_system) {
            throw ValidationException::withMessages([
                'page' => [
                    'This is a required store page and cannot be deleted. '
                    . 'Set its status to draft instead if you need to take it offline.',
                ],
            ]);
        }

        $wasLive = $this->isLive($page);
        $slug = $page->slug;

        DB::transaction(function () use ($page): void {
            $this->media->delete($page->featured_image);
            $this->media->delete($page->og_image);

            $page->delete();
        });

        ContentChanged::dispatch('page', $slug, false, $wasLive);
    }

    /**
     * Store or replace a page's images.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeImages(CmsPage $page, array $data): void
    {
        $changed = false;

        foreach (['featured_image', 'og_image'] as $field) {
            $file = $data[$field] ?? null;

            if ($file instanceof UploadedFile) {
                $page->{$field} = $this->media->replace($file, $page->{$field}, 'pages');
                $changed = true;

                continue;
            }

            if (array_key_exists($field, $data) && $data[$field] === null && $page->{$field} !== null) {
                $this->media->delete($page->{$field});
                $page->{$field} = null;
                $changed = true;
            }
        }

        if ($changed) {
            $page->save();
        }
    }

    /**
     * Stamp `published_at` the first time a page goes live, and never again.
     *
     * A "last updated" notice on a policy page should show when the policy took
     * effect, not when someone last fixed a typo — and re-stamping on every
     * save would make the two indistinguishable. `updated_at` covers edits.
     */
    private function resolvePublishedAt(string $status, ?Carbon $existing): ?Carbon
    {
        if (! PublishStatus::from($status)->isPublishable()) {
            return $existing;
        }

        return $existing ?? Carbon::now();
    }

    /**
     * Derive an excerpt from the body when none was supplied.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveExcerpt(array $data): ?string
    {
        if (! empty($data['excerpt'])) {
            return $this->sanitiser->toPlainText((string) $data['excerpt'], 300);
        }

        $excerpt = $this->sanitiser->toPlainText($data['content'] ?? null, 200);

        return $excerpt !== '' ? $excerpt : null;
    }

    /**
     * Derive a meta description from the body when none was supplied.
     *
     * A page with no description at all leaves search engines to invent one
     * from arbitrary body text, so falling back to the opening prose is
     * strictly better than an empty tag.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveSeoDescription(array $data): ?string
    {
        if (! empty($data['seo_description'])) {
            return $this->sanitiser->toPlainText((string) $data['seo_description'], 300);
        }

        // 160 characters is the practical ceiling before search results
        // truncate the snippet.
        $description = $this->sanitiser->toPlainText(
            $data['excerpt'] ?? $data['content'] ?? null,
            160,
        );

        return $description !== '' ? $description : null;
    }

    private function isLive(CmsPage $page): bool
    {
        return $page->status->isPublishable() && $page->isWithinWindow();
    }

    private function nextSortOrder(): int
    {
        return (int) CmsPage::query()->max('sort_order') + 1;
    }

    private function cacheEnabled(): bool
    {
        return (bool) config('content.cache.enabled', true)
            && Cache::getStore() instanceof \Illuminate\Cache\TaggableStore;
    }

    private function cacheTag(): string
    {
        return (string) config('content.cache.tag', 'content');
    }
}
