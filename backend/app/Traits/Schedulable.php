<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * A start/end visibility window on a model.
 *
 * Shared by banners, homepage sections, and CMS pages because all three answer
 * the same question — "is this live right now?" — and getting it wrong in three
 * places independently is how a retired campaign reappears.
 *
 * Two rules the implementation exists to enforce:
 *
 *   - The window is evaluated in SQL, not in PHP. Loading every row and then
 *     filtering in a collection would make counts, pagination, and `exists()`
 *     lie about what is actually visible.
 *   - A null end is open-ended, not expired. The obvious `where('ends_at','>',now())`
 *     silently hides every row that was never given an end date, which is most
 *     of them.
 *
 * Requires `starts_at` and `ends_at` timestamp columns and their casts.
 */
trait Schedulable
{
    /**
     * Rows whose scheduling window contains the given moment.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithinWindow(Builder $query, ?Carbon $at = null): Builder
    {
        $at ??= Carbon::now();

        return $query
            ->where(fn (Builder $inner) => $inner
                ->whereNull('starts_at')
                ->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $inner) => $inner
                ->whereNull('ends_at')
                ->orWhere('ends_at', '>', $at));
    }

    /**
     * Rows whose window has not opened yet — the admin panel's "Scheduled"
     * filter, and the set a cache-warming job would look at.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeScheduled(Builder $query, ?Carbon $at = null): Builder
    {
        return $query->where('starts_at', '>', $at ?? Carbon::now());
    }

    /**
     * Rows whose window has closed.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeExpired(Builder $query, ?Carbon $at = null): Builder
    {
        return $query->whereNotNull('ends_at')->where('ends_at', '<=', $at ?? Carbon::now());
    }

    /**
     * The in-PHP counterpart, for a model already in memory.
     *
     * Never used to filter a query — see the class docblock.
     */
    public function isWithinWindow(?Carbon $at = null): bool
    {
        $at ??= Carbon::now();

        if ($this->starts_at !== null && $this->starts_at->greaterThan($at)) {
            return false;
        }

        return ! ($this->ends_at !== null && $this->ends_at->lessThanOrEqualTo($at));
    }

    public function isScheduled(?Carbon $at = null): bool
    {
        return $this->starts_at !== null && $this->starts_at->greaterThan($at ?? Carbon::now());
    }

    public function isExpired(?Carbon $at = null): bool
    {
        return $this->ends_at !== null && $this->ends_at->lessThanOrEqualTo($at ?? Carbon::now());
    }

    /**
     * A single word describing where the model sits relative to its window,
     * for the admin panel's status chip.
     */
    public function windowState(?Carbon $at = null): string
    {
        if ($this->isScheduled($at)) {
            return 'scheduled';
        }

        return $this->isExpired($at) ? 'expired' : 'live';
    }

    /**
     * The next moment this row's visibility changes, if any.
     *
     * The caller uses it to bound a cache TTL: caching a flash sale for ten
     * minutes when it ends in two would leave it advertised after it closed.
     */
    public function nextTransitionAt(?Carbon $at = null): ?Carbon
    {
        $at ??= Carbon::now();

        if ($this->starts_at !== null && $this->starts_at->greaterThan($at)) {
            return $this->starts_at;
        }

        if ($this->ends_at !== null && $this->ends_at->greaterThan($at)) {
            return $this->ends_at;
        }

        return null;
    }
}
