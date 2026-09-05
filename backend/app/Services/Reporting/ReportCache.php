<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Support\DateRange;
use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The one place reporting results are cached, and the one place they are
 * purged.
 *
 * ## Why a dedicated class rather than `Cache::remember()` at each call site
 *
 * A dashboard makes a dozen aggregate queries per load and staff leave it open
 * all day, so caching is not an optimisation here — it is the difference
 * between a panel that loads and one that pins the database. Spreading
 * `Cache::tags(...)->remember(...)` across three services would mean three
 * copies of the tag name, three TTL decisions, and — the actual failure mode —
 * a purge that misses one of them, leaving a "total sales" figure that
 * disagrees with the order list beneath it.
 *
 * Everything cached by reporting therefore lives under one tag and is dropped
 * wholesale by {@see flush()}, which `ReportingDataChanged` calls whenever an
 * order, payment, or stock level moves.
 *
 * ## Degrading without tags
 *
 * Tagged caching needs a taggable store; Redis is the configured default, but
 * the array store used in some tests is not taggable and the file store never
 * is. Rather than branch at every call site, {@see enabled()} reports false
 * when tags are unavailable and every method falls through to computing the
 * value directly. A store without tags is slower, never wrong.
 */
final class ReportCache
{
    /**
     * Remember a reporting result under the reports tag.
     *
     * The TTL is chosen from the range rather than passed in: a window that has
     * already closed cannot produce different numbers no matter how long it is
     * held, while one containing "now" must expire quickly or the dashboard
     * reports stale figures to someone watching orders arrive.
     *
     * @template TValue
     *
     * @param  callable(): TValue  $compute
     * @return TValue
     */
    public function remember(string $key, DateRange $range, callable $compute): mixed
    {
        if (! $this->enabled()) {
            return $compute();
        }

        return $this->store()->remember(
            $this->qualify($key, $range),
            $this->ttlFor($range),
            $compute,
        );
    }

    /**
     * Remember a result that is not scoped to a date range — a catalog count,
     * a low-stock list.
     *
     * Always held at the short TTL. These describe the store as it is right
     * now, and "right now" is never a closed period.
     *
     * @template TValue
     *
     * @param  callable(): TValue  $compute
     * @return TValue
     */
    public function rememberLive(string $key, callable $compute): mixed
    {
        if (! $this->enabled()) {
            return $compute();
        }

        return $this->store()->remember(
            'reports:'.$key,
            (int) config('reporting.cache.ttl', 300),
            $compute,
        );
    }

    /**
     * Remember an integer, cast on the way out.
     *
     * ## Why this exists rather than a plain `(int)` at each call site
     *
     * Cache stores do not agree on what they return. The array store used in
     * tests round-trips a value through serialisation and hands back exactly
     * what went in; Redis returns everything as a string. So a counter cached
     * as `12345` comes back as `"12345"`, and a method declaring `: int`
     * fails — but only once the entry is warm, which means never in a fresh
     * test run and always in production a minute after deploy.
     *
     * Centralising the cast means a new metric cannot reintroduce that bug by
     * forgetting it.
     *
     * @param  callable(): int  $compute
     */
    public function rememberInt(string $key, callable $compute): int
    {
        return (int) $this->rememberLive($key, $compute);
    }

    /**
     * The range-scoped counterpart of {@see rememberInt()}.
     *
     * @param  callable(): int  $compute
     */
    public function rememberIntFor(string $key, DateRange $range, callable $compute): int
    {
        return (int) $this->remember($key, $range, $compute);
    }

    /**
     * Drop every cached reporting result.
     *
     * Deliberately all-or-nothing. The alternative — invalidating only the keys
     * an event could have affected — requires knowing which of dozens of
     * aggregates a single order touches, and being wrong in that calculation
     * shows up as a dashboard that quietly disagrees with itself for an hour.
     * Recomputing a dashboard costs a second; reconciling a wrong one costs an
     * afternoon.
     */
    public function flush(): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->store()->flush();
    }

    /**
     * Whether results are being cached at all.
     *
     * False when disabled by config, or when the configured store cannot carry
     * tags — see the class docblock.
     */
    public function enabled(): bool
    {
        return (bool) config('reporting.cache.enabled', true)
            && Cache::getStore() instanceof TaggableStore;
    }

    /**
     * @return Repository
     */
    private function store()
    {
        return Cache::tags([$this->tag()]);
    }

    private function tag(): string
    {
        return (string) config('reporting.cache.tag', 'reports');
    }

    /**
     * Build the full cache key.
     *
     * The range is part of the key rather than the value, so two admins looking
     * at different periods do not evict each other's dashboard.
     */
    private function qualify(string $key, DateRange $range): string
    {
        return sprintf('reports:%s:%s', $key, $range->cacheKey());
    }

    /**
     * A closed window's numbers are final, so it is held for the long TTL; a
     * window containing the present moment gets the short one.
     */
    private function ttlFor(DateRange $range): int
    {
        return $range->isClosed()
            ? (int) config('reporting.cache.long_ttl', 3600)
            : (int) config('reporting.cache.ttl', 300);
    }
}
