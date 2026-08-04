<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ContentChanged;
use App\Jobs\RevalidateFrontendCache;
use Illuminate\Support\Facades\Cache;

/**
 * Bridges storefront content changes to the caches that serve them.
 *
 * Two caches are cleared for different reasons: this application's own tagged
 * cache, so the next API response is rebuilt from the database; and the Next.js
 * ISR cache, so the storefront stops serving a page assembled from the previous
 * configuration.
 *
 * The HTTP call is queued, so a slow or unreachable frontend never blocks an
 * operator's save.
 */
final class InvalidateContentCache
{
    /**
     * Cache tag shared with HomepageService and CmsPageService, and kept in
     * sync with the frontend's CACHE_TAGS.content.
     */
    public const TAG = 'content';

    public function handle(ContentChanged $event): void
    {
        $this->flushLocalCache();

        if (! $event->affectsStorefront()) {
            return;
        }

        $this->revalidateFrontend([$event->entity, $event->identifier]);
    }

    /**
     * A catalog change also invalidates the homepage.
     *
     * The homepage payload embeds resolved product cards, so unpublishing a
     * product or changing its price leaves the cached homepage advertising the
     * old state — the one place where "the catalog cache was purged" is not
     * enough, because the stale copy lives under a different tag.
     *
     * Only the local cache is flushed here: InvalidateCatalogCache has already
     * dispatched the frontend revalidation for this same change, and the
     * storefront's homepage carries the catalog tag too. Dispatching a second
     * job would double every revalidation for no additional effect.
     */
    public function handleCatalogChanged(\App\Events\CatalogChanged $event): void
    {
        if (! $event->affectsStorefront()) {
            return;
        }

        $this->flushLocalCache();
    }

    /**
     * Flush the whole content tag rather than one key.
     *
     * Targeted eviction would be wrong here: a banner belongs to a placement,
     * a placement feeds a section, and a section is part of the cached homepage
     * payload. Evicting only `content:banner:7` would leave the assembled
     * homepage holding the old slide. The tag is small — one homepage payload
     * and a handful of pages — so rebuilding it costs far less than serving a
     * page nobody configured.
     */
    private function flushLocalCache(): void
    {
        $store = Cache::getStore();

        // Tagged flushing needs a taggable store; the file and database drivers
        // are not. A full flush there would evict unrelated entries (sessions,
        // rate limiters), so those drivers wait out the TTL instead.
        if ($store instanceof \Illuminate\Cache\TaggableStore) {
            Cache::tags([self::TAG])->flush();
        }
    }

    /**
     * @param  array<int, string|null>  $context
     */
    private function revalidateFrontend(array $context): void
    {
        if (! config('api.revalidation.enabled')) {
            return;
        }

        RevalidateFrontendCache::dispatch(
            [self::TAG],
            array_values(array_filter($context, static fn (?string $value): bool => $value !== null)),
        );
    }
}
