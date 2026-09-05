<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReportPeriod;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Reporting\DashboardService;
use App\Support\DateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dashboard metrics.
 *
 * The assertions that matter most here are about *which orders count*. A
 * dashboard that adds up every row in `orders` reports money the store never
 * received — a cancelled order and a failed payment are both rows — so each
 * test below plants an order that must not be counted alongside one that must,
 * and asserts on the difference.
 */
final class DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function dashboard(): DashboardService
    {
        return $this->app->make(DashboardService::class);
    }

    private function range(ReportPeriod $period = ReportPeriod::Last30Days): DateRange
    {
        return DateRange::forPeriod($period);
    }

    /*
    |--------------------------------------------------------------------------
    | Revenue recognition
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function only_settled_orders_count_toward_sales(): void
    {
        Order::factory()->paid()->totals(10_000)->create();

        // Neither of these is money the store has.
        Order::factory()->paymentStatus(PaymentStatus::Pending)->totals(50_000)->create();
        Order::factory()->paymentStatus(PaymentStatus::Failed)->totals(70_000)->create();

        $metrics = $this->dashboard()->metrics($this->range());

        $this->assertSame(10_000, $metrics['sales']['period']);
    }

    #[Test]
    public function a_cancelled_order_is_excluded_even_when_it_was_paid(): void
    {
        Order::factory()->paid()->totals(10_000)->create();

        Order::factory()
            ->paymentStatus(PaymentStatus::Paid)
            ->status(OrderStatus::Cancelled)
            ->totals(90_000)
            ->create();

        $metrics = $this->dashboard()->metrics($this->range());

        // Paid then cancelled is money owed back, not money earned.
        $this->assertSame(10_000, $metrics['sales']['period']);
    }

    #[Test]
    public function sales_are_reported_net_of_refunds(): void
    {
        /*
         * Created in the refunded state rather than transitioned into it.
         * Order guards `payment_status` against direct assignment — it must
         * move through OrderService — and this test is about how the
         * dashboard *reads* a partially refunded order, not about how one
         * comes to be in that state.
         */
        Order::factory()
            ->paymentStatus(PaymentStatus::PartiallyRefunded)
            ->totals(10_000)
            ->create(['refunded_total' => 2_500]);

        $metrics = $this->dashboard()->metrics($this->range());

        // The unrefunded remainder is genuine revenue; dropping the whole
        // order would understate it.
        $this->assertSame(7_500, $metrics['sales']['period']);
    }

    #[Test]
    public function the_order_count_includes_orders_that_never_became_revenue(): void
    {
        Order::factory()->paid()->totals(10_000)->create();
        Order::factory()->cancelled()->totals(10_000)->create();
        Order::factory()->paymentStatus(PaymentStatus::Failed)->totals(10_000)->create();

        $metrics = $this->dashboard()->metrics($this->range());

        // "How many orders came in" is a different question from "how much did
        // we take", and an operations dashboard is asking the first.
        $this->assertSame(3, $metrics['orders']['period']);
        $this->assertSame(10_000, $metrics['sales']['period']);
    }

    /*
    |--------------------------------------------------------------------------
    | Windows
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function todays_sales_exclude_yesterdays(): void
    {
        Order::factory()->paid()->totals(4_000)->create();

        Order::factory()->paid()->totals(9_000)->create([
            'created_at' => Carbon::now()->subDay(),
        ]);

        $metrics = $this->dashboard()->metrics($this->range(ReportPeriod::Today));

        $this->assertSame(4_000, $metrics['sales']['today']);
    }

    #[Test]
    public function an_order_placed_late_today_is_still_inside_todays_range(): void
    {
        // The bug this guards: a range whose upper bound is midnight rather
        // than end-of-day silently drops everything placed during the day.
        Carbon::setTestNow(Carbon::today()->setTime(9, 0));

        Order::factory()->paid()->totals(4_000)->create([
            'created_at' => Carbon::today()->setTime(23, 30),
        ]);

        $metrics = $this->dashboard()->metrics($this->range(ReportPeriod::Today));

        $this->assertSame(4_000, $metrics['sales']['today']);

        Carbon::setTestNow();
    }

    #[Test]
    public function orders_outside_the_selected_range_are_not_counted(): void
    {
        Order::factory()->paid()->totals(5_000)->create();

        Order::factory()->paid()->totals(80_000)->create([
            'created_at' => Carbon::now()->subDays(90),
        ]);

        $metrics = $this->dashboard()->metrics($this->range(ReportPeriod::Last30Days));

        $this->assertSame(5_000, $metrics['sales']['period']);

        // But lifetime sales, which have no window, see both.
        $this->assertSame(85_000, $metrics['sales']['total']);
    }

    /*
    |--------------------------------------------------------------------------
    | Status counts, customers, products
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function status_counts_report_the_current_backlog(): void
    {
        Order::factory()->count(3)->status(OrderStatus::Pending)->create();
        Order::factory()->count(2)->delivered()->create();
        Order::factory()->cancelled()->create();

        $metrics = $this->dashboard()->metrics($this->range());

        $this->assertSame(3, $metrics['orders']['pending']);
        $this->assertSame(2, $metrics['orders']['delivered']);
        $this->assertSame(1, $metrics['orders']['cancelled']);
    }

    #[Test]
    public function status_counts_ignore_the_selected_range(): void
    {
        // A pending order from long ago is still work in the queue today, so
        // filtering the dashboard to a recent window must not hide it.
        Order::factory()->status(OrderStatus::Pending)->create([
            'created_at' => Carbon::now()->subYears(2),
        ]);

        $metrics = $this->dashboard()->metrics($this->range(ReportPeriod::Today));

        $this->assertSame(1, $metrics['orders']['pending']);
    }

    #[Test]
    public function customer_and_product_totals_are_reported(): void
    {
        User::factory()->count(4)->create();
        Product::factory()->count(3)->published()->create(['stock' => 50, 'low_stock_threshold' => 5]);

        $metrics = $this->dashboard()->metrics($this->range());

        $this->assertSame(4, $metrics['customers']['total']);
        $this->assertSame(3, $metrics['products']['total']);
    }

    #[Test]
    public function low_stock_counts_the_reorder_point_not_the_empty_shelf(): void
    {
        Product::factory()->published()->create(['stock' => 3, 'low_stock_threshold' => 5]);
        Product::factory()->published()->create(['stock' => 50, 'low_stock_threshold' => 5]);
        Product::factory()->published()->create(['stock' => 0, 'low_stock_threshold' => 5]);

        $metrics = $this->dashboard()->metrics($this->range());

        // At-or-below the reorder point but not yet empty. An out-of-stock
        // product is a separate, more urgent state.
        $this->assertSame(1, $metrics['products']['low_stock']);
        $this->assertSame(1, $metrics['products']['out_of_stock']);
    }

    #[Test]
    public function the_low_stock_panel_lists_the_scarcest_first(): void
    {
        Product::factory()->published()->create(['name' => 'Roomy', 'stock' => 4, 'low_stock_threshold' => 5]);
        Product::factory()->published()->create(['name' => 'Critical', 'stock' => 1, 'low_stock_threshold' => 5]);

        $products = $this->dashboard()->lowStockProducts();

        $this->assertCount(2, $products);
        $this->assertSame('Critical', $products[0]['name']);
    }

    /*
    |--------------------------------------------------------------------------
    | Derived figures
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function average_order_value_divides_sales_by_order_count(): void
    {
        Order::factory()->paid()->totals(10_000)->create();
        Order::factory()->paid()->totals(20_000)->create();

        $metrics = $this->dashboard()->metrics($this->range());

        $this->assertSame(15_000, $metrics['average_order_value']);
    }

    #[Test]
    public function average_order_value_is_zero_rather_than_a_division_error_when_nothing_sold(): void
    {
        $metrics = $this->dashboard()->metrics($this->range());

        $this->assertSame(0, $metrics['average_order_value']);
        $this->assertSame(0, $metrics['sales']['period']);
    }

    #[Test]
    public function growth_from_nothing_is_reported_as_no_comparison(): void
    {
        Order::factory()->paid()->totals(10_000)->create();

        $metrics = $this->dashboard()->metrics($this->range(ReportPeriod::Today));

        // There is no meaningful percentage increase from zero, and "+100%" on
        // a store's first sale would be worse than showing nothing.
        $this->assertNull($metrics['sales']['period_change']);
    }

    #[Test]
    public function a_period_on_period_change_is_computed_against_the_preceding_window(): void
    {
        Order::factory()->paid()->totals(10_000)->create([
            'created_at' => Carbon::now()->subDays(2),
        ]);

        // The 7 days before the last 7 days.
        Order::factory()->paid()->totals(5_000)->create([
            'created_at' => Carbon::now()->subDays(10),
        ]);

        $metrics = $this->dashboard()->metrics($this->range(ReportPeriod::Last7Days));

        $this->assertSame(10_000, $metrics['sales']['period']);
        $this->assertSame(5_000, $metrics['sales']['period_previous']);
        $this->assertSame(100.0, $metrics['sales']['period_change']);
    }
}
