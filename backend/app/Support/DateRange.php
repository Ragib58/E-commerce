<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ReportGranularity;
use App\Enums\ReportPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * A resolved reporting window: two instants, a granularity, and a cache key.
 *
 * ## Why this is a value object rather than two Carbon arguments
 *
 * Every metric, chart, report, and export in this phase is scoped to a range,
 * and three things about a range are easy to get subtly wrong in each of them
 * independently:
 *
 *  - **The upper bound.** `whereBetween('created_at', [$from, $to])` with a
 *    `$to` of "2026-03-01" silently excludes every order placed that day after
 *    midnight. This class always resolves `to` to the *end* of its day, so the
 *    inclusive range a human means is the range the query runs.
 *  - **The timezone.** "Today" is a question about the store's timezone, not
 *    the server's. Boundaries are computed in the app timezone and handed to
 *    the database as that same wall-clock time, matching how `created_at` was
 *    written.
 *  - **The comparison window.** A metric showing "+12% vs previous period"
 *    needs a previous period of exactly equal length, immediately preceding.
 *    {@see previous()} derives it once instead of each caller subtracting a
 *    different guess.
 *
 * Instances are immutable; every method returns a new range.
 */
final readonly class DateRange
{
    public function __construct(
        public Carbon $from,
        public Carbon $to,
        public ReportGranularity $granularity,
        public ReportPeriod $period,
    ) {}

    /**
     * Resolve a preset — or an explicit pair of dates for {@see
     * ReportPeriod::Custom} — into a concrete window.
     *
     * @throws ValidationException when a custom range is missing dates, is
     *                             inverted, or exceeds the configured span.
     */
    public static function forPeriod(
        ReportPeriod $period,
        ?string $from = null,
        ?string $to = null,
    ): self {
        $now = Carbon::now();

        if ($period === ReportPeriod::Custom) {
            return self::custom($from, $to);
        }

        [$start, $end] = match ($period) {
            ReportPeriod::Today => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            ReportPeriod::Last7Days => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            ReportPeriod::Last30Days => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            ReportPeriod::ThisMonth => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            ReportPeriod::ThisYear => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            ReportPeriod::Custom => throw new \LogicException('Unreachable: handled above.'),
        };

        return new self($start, $end, $period->granularity(), $period);
    }

    /**
     * @throws ValidationException
     */
    private static function custom(?string $from, ?string $to): self
    {
        if ($from === null || $to === null) {
            throw ValidationException::withMessages([
                'from' => ['A custom range needs both a start and an end date.'],
            ]);
        }

        try {
            $start = Carbon::parse($from)->startOfDay();
            $end = Carbon::parse($to)->endOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'from' => ['Those dates could not be understood. Use YYYY-MM-DD.'],
            ]);
        }

        if ($start->greaterThan($end)) {
            throw ValidationException::withMessages([
                'from' => ['The start date must fall before the end date.'],
            ]);
        }

        $maxDays = (int) config('reporting.limits.max_range_days', 1096);

        // +1 because the range is inclusive of both endpoints: the 1st to the
        // 1st is one day, not zero.
        if ($start->diffInDays($end) + 1 > $maxDays) {
            throw ValidationException::withMessages([
                'from' => [sprintf('A custom range may not exceed %d days.', $maxDays)],
            ]);
        }

        return new self($start, $end, self::granularityForSpan($start, $end), ReportPeriod::Custom);
    }

    /**
     * Pick a bucket size that keeps a custom range's chart readable.
     *
     * The thresholds target roughly 7–60 points on the axis. A user asking for
     * two years does not want 730 bars, and one asking for a single day does
     * not want one.
     */
    private static function granularityForSpan(Carbon $start, Carbon $end): ReportGranularity
    {
        $days = (int) $start->diffInDays($end) + 1;

        return match (true) {
            $days <= 2 => ReportGranularity::Hour,
            $days <= 62 => ReportGranularity::Day,
            $days <= 365 => ReportGranularity::Week,
            default => ReportGranularity::Month,
        };
    }

    /**
     * The equally-long window immediately before this one, for period-on-period
     * comparison.
     *
     * Length is measured in seconds rather than by re-applying the preset, so
     * the comparison is like-for-like even when the current window is a partial
     * period. "This month" on the 10th compares against the 10 days before it
     * started, not against all of last month — comparing 10 days of sales to 31
     * would report a collapse every month.
     */
    public function previous(): self
    {
        $seconds = $this->from->diffInSeconds($this->to);

        $end = $this->from->copy()->subSecond();
        $start = $end->copy()->subSeconds($seconds);

        return new self($start, $end, $this->granularity, $this->period);
    }

    /**
     * A stable fragment identifying this range in a cache key.
     *
     * Rounded to the minute deliberately. Left at second precision, "today"
     * would produce a new key on every request — a cache that never hits and
     * an unbounded set of entries under the tag.
     */
    public function cacheKey(): string
    {
        return sprintf(
            '%s:%s:%s:%s',
            $this->period->value,
            $this->from->format('YmdHi'),
            $this->to->format('YmdHi'),
            $this->granularity->value,
        );
    }

    /**
     * Whether this range's end is in the past, so its data can no longer
     * change and may be cached for longer.
     */
    public function isClosed(): bool
    {
        return $this->to->isPast();
    }

    /**
     * The number of buckets a series over this range will contain.
     */
    public function bucketCount(): int
    {
        return match ($this->granularity) {
            ReportGranularity::Hour => (int) $this->from->diffInHours($this->to) + 1,
            ReportGranularity::Day => (int) $this->from->diffInDays($this->to) + 1,
            ReportGranularity::Week => (int) $this->from->diffInWeeks($this->to) + 1,
            ReportGranularity::Month => (int) $this->from->diffInMonths($this->to) + 1,
        };
    }

    /**
     * Every bucket label in this range, in order, including empty ones.
     *
     * The series builder uses this to fill gaps: `GROUP BY` returns no row for
     * a day with no orders, and a chart that omits the point draws a line
     * across it as though the period were flat rather than zero.
     *
     * @return array<int, string>
     */
    public function buckets(): array
    {
        $labels = [];
        $cursor = $this->alignToGranularity($this->from);

        while ($cursor->lessThanOrEqualTo($this->to)) {
            $labels[] = $this->labelFor($cursor);

            $cursor = match ($this->granularity) {
                ReportGranularity::Hour => $cursor->copy()->addHour(),
                ReportGranularity::Day => $cursor->copy()->addDay(),
                ReportGranularity::Week => $cursor->copy()->addWeek(),
                ReportGranularity::Month => $cursor->copy()->addMonth(),
            };
        }

        return $labels;
    }

    /**
     * Snap an instant back to the start of its bucket, so a range beginning
     * mid-month does not produce a first bucket the database never emits.
     */
    private function alignToGranularity(Carbon $moment): Carbon
    {
        return match ($this->granularity) {
            ReportGranularity::Hour => $moment->copy()->startOfHour(),
            ReportGranularity::Day => $moment->copy()->startOfDay(),
            ReportGranularity::Week => $moment->copy()->startOfWeek(),
            ReportGranularity::Month => $moment->copy()->startOfMonth(),
        };
    }

    /**
     * The label the database's bucket expression will produce for this instant.
     *
     * Must match {@see ReportGranularity::sqlBucket()} exactly — the two are
     * joined on string equality when gaps are filled, so a mismatch shows as
     * every bucket being empty rather than as an error.
     */
    private function labelFor(Carbon $moment): string
    {
        return match ($this->granularity) {
            ReportGranularity::Hour => $moment->format('Y-m-d H:00'),
            ReportGranularity::Day => $moment->format('Y-m-d'),
            ReportGranularity::Week => $moment->format('o-\WW'),
            ReportGranularity::Month => $moment->format('Y-m'),
        };
    }

    /**
     * @return array{from: string, to: string, period: string, granularity: string, label: string}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toIso8601String(),
            'to' => $this->to->toIso8601String(),
            'period' => $this->period->value,
            'granularity' => $this->granularity->value,
            'label' => $this->period->label(),
        ];
    }
}
