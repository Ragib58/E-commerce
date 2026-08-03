<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\CatalogChanged;
use App\Events\StockAdjusted;
use App\Jobs\RevalidateFrontendCache;
use Illuminate\Support\Facades\Cache;

/**
 * Bridges catalog and stock changes to the storefront's cache.
 *
 * Two caches are cleared for different reasons: this application's own tagged
 * cache (so the next API response is rebuilt from the database), and the
 * Next.js ISR cache (so the storefront stops serving a stale page).
 *
 * The HTTP call is queued so a slow or unreachable frontend never blocks an
 * admin's save.
 */
final class InvalidateCatalogCache
{
    /**
     * Cache tag shared with CatalogService. Kept in sync with the frontend's
     * CACHE_TAGS.catalog.
     */
    public const TAG = 'catalog';

    public function handleCatalogChanged(CatalogChanged $event): void
    {
        $this->flushLocalCache();

        if (! $event->affectsStorefront()) {
            return;
        }

        $this->revalidateFrontend([$event->entity, $event->slug]);
    }

    /**
     * A stock change only alters a rendered page when it crosses the
     * in-stock/out-of-stock boundary — a drop from 90 to 80 changes nothing a
     * shopper sees, and purging for it would throw away a warm cache on every
     * order.
     */
    public function handleStockAdjusted(StockAdjusted $event): void
    {
        if (! $event->changesAvailability()) {
            return;
        }

        $this->flushLocalCache();

        $this->revalidateFrontend(['product', $event->movement->product?->slug]);
    }

    private function flushLocalCache(): void
    {
        $store = Cache::getStore();

        // Tagged flushing needs a taggable store; the file and database drivers
        // are not. Falling back to a full flush would evict unrelated entries
        // (sessions, rate limiters), so those drivers simply wait out the TTL.
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
