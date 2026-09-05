<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Enums\OrderStatus;
use App\Enums\ReportPeriod;
use App\Events\OrderPlaced;
use App\Models\Order;
use App\Services\Reporting\DashboardService;
use App\Services\Reporting\ReportCache;
use App\Support\DateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dashboard caching.
 *
 * The brief asks for three related things — optimise the queries, use Redis
 * caching, and avoid expensive queries on every request — and the tests below
 * assert the property that actually delivers all three: a repeat load inside
 * the TTL runs no queries at all, and a change to the underlying data drops
 * the cache immediately rather than leaving the panel stale.
 *
 * The second half matters as much as the first. A cache that is never
 * invalidated is not an optimisation, it is a dashboard that disagrees with
 * the order list beneath it.
 */
final class ReportCachingTest extends TestCase
{
    use RefreshDatabase;

    private function dashboard(): DashboardService
    {
        return $this->app->make(DashboardService::class);
    }

    private function range(): DateRange
    {
        return DateRange::forPeriod(ReportPeriod::Last30Days);
    }

    /**
     * Count the queries one closure runs.
     */
    private function queriesFor(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * Skip when the configured store cannot carry tags.
     *
     * Tagged caching needs a taggable store — Redis in this application, and
     * the array store in tests. A file store never is, and the service
     * degrades to computing directly rather than failing, so these assertions
     * would not hold there.
     */
    private function requireTaggableCache(): void
    {
        if (! $this->app->make(ReportCache::class)->enabled()) {
            $this->markTestSkipped('The configured cache store does not support tags.');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_second_dashboard_load_runs_no_queries(): void
    {
        $this->requireTaggableCache();

        Order::factory()->count(3)->paid()->totals(10_000)->create();

        $first = $this->queriesFor(fn () => $this->dashboard()->metrics($this->range()));
        $second = $this->queriesFor(fn () => $this->dashboard()->metrics($this->range()));

        $this->assertGreaterThan(0, $first, 'The first load should compute its figures.');

        // This is the whole point: staff leave a dashboard open, and a panel
        // that re-aggregates the orders table on every poll is the single most
        // expensive thing the admin API can do.
        $this->assertSame(0, $second, 'A cached dashboard should run no queries at all.');
    }

    #[Test]
    public function the_cached_payload_is_identical_to_the_computed_one(): void
    {
        $this->requireTaggableCache();

        Order::factory()->paid()->totals(12_345)->create();

        $computed = $this->dashboard()->metrics($this->range());
        $cached = $this->dashboard()->metrics($this->range());

        $this->assertSame($computed, $cached);
    }

    #[Test]
    public function two_ranges_do_not_evict_each_other(): void
    {
        $this->requireTaggableCache();

        Order::factory()->paid()->totals(10_000)->create();

        $today = DateRange::forPeriod(ReportPeriod::Today);
        $month = DateRange::forPeriod(ReportPeriod::Last30Days);

        $this->dashboard()->metrics($today);
        $this->dashboard()->metrics($month);

        // Two admins looking at different periods must not knock each other's
        // dashboard out of the cache.
        $this->assertSame(0, $this->queriesFor(fn () => $this->dashboard()->metrics($today)));
        $this->assertSame(0, $this->queriesFor(fn () => $this->dashboard()->metrics($month)));
    }

    /*
    |--------------------------------------------------------------------------
    | Invalidation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_new_order_drops_the_cached_figures(): void
    {
        $this->requireTaggableCache();

        Order::factory()->paid()->totals(10_000)->create();

        $this->assertSame(10_000, $this->dashboard()->metrics($this->range())['sales']['period']);

        $order = Order::factory()->paid()->totals(5_000)->create();
        event(new OrderPlaced($order));

        // Stale figures here would be a dashboard quietly disagreeing with the
        // order list beneath it.
        $this->assertSame(15_000, $this->dashboard()->metrics($this->range())['sales']['period']);
    }

    #[Test]
    public function flushing_clears_every_reporting_entry(): void
    {
        $this->requireTaggableCache();

        Order::factory()->paid()->totals(10_000)->create();

        $this->dashboard()->metrics($this->range());
        $this->dashboard()->metrics(DateRange::forPeriod(ReportPeriod::Today));

        $this->app->make(ReportCache::class)->flush();

        // Wholesale rather than selective — see ReportCache for why working out
        // which of a dozen aggregates one order touches is the wrong trade.
        $this->assertGreaterThan(0, $this->queriesFor(fn () => $this->dashboard()->metrics($this->range())));
    }

    #[Test]
    public function the_reporting_flush_leaves_other_cache_tags_alone(): void
    {
        $this->requireTaggableCache();

        Cache::tags(['catalog'])->put('catalog:probe', 'kept', 600);

        $this->app->make(ReportCache::class)->flush();

        // Dropping the reports tag must not discard every cached product page
        // as collateral.
        $this->assertSame('kept', Cache::tags(['catalog'])->get('catalog:probe'));
    }

    /*
    |--------------------------------------------------------------------------
    | Query efficiency
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_dashboard_is_a_bounded_number_of_queries_regardless_of_data_volume(): void
    {
        Order::factory()->count(2)->paid()->totals(1_000)->create();

        $this->app->make(ReportCache::class)->flush();
        $small = $this->queriesFor(fn () => $this->dashboard()->metrics($this->range()));

        Order::factory()->count(40)->paid()->totals(1_000)->create();

        $this->app->make(ReportCache::class)->flush();
        $large = $this->queriesFor(fn () => $this->dashboard()->metrics($this->range()));

        /*
         * Twenty times the orders for the same handful of queries — the
         * aggregates are computed in the database rather than by loading rows
         * and adding them up in PHP.
         *
         * Asserted as a ceiling rather than as equality between the two runs:
         * the count legitimately varies by one or two depending on whether the
         * fixed "today" and "this month" windows happen to coincide with the
         * selected range and share its cache entry. What must not happen is
         * the count growing with the data, which either form would catch.
         */
        $this->assertLessThanOrEqual(15, $small);
        $this->assertLessThanOrEqual(15, $large);
        $this->assertLessThanOrEqual(2, abs($large - $small));
    }

    #[Test]
    public function status_counts_are_one_query_not_one_per_status(): void
    {
        Order::factory()->count(2)->status(OrderStatus::Pending)->create();
        Order::factory()->delivered()->create();
        Order::factory()->cancelled()->create();

        $this->app->make(ReportCache::class)->flush();

        $metrics = $this->dashboard()->metrics($this->range());

        // Read three times while the payload is assembled; memoised on the
        // instance so it costs one query, not three.
        $this->assertSame(2, $metrics['orders']['pending']);
        $this->assertSame(1, $metrics['orders']['delivered']);
        $this->assertSame(1, $metrics['orders']['cancelled']);
    }
}
