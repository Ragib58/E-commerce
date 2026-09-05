<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReportGranularity;
use App\Enums\ReportPeriod;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Reporting\ChartService;
use App\Support\DateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Chart series.
 *
 * Two properties get the most attention below, because both are silent when
 * they break:
 *
 *  - **Gaps are filled.** A day with no orders returns no row from `GROUP BY`,
 *    and a chart that omits the point draws a flat line across it rather than a
 *    dip to zero — a sales chart that lies about a bad week.
 *  - **Every category appears.** A payment-method chart that lists only methods
 *    used in the window is ambiguous between "nobody used bKash" and "bKash is
 *    not offered".
 */
final class ChartServiceTest extends TestCase
{
    use RefreshDatabase;

    private function charts(): ChartService
    {
        return $this->app->make(ChartService::class);
    }

    private function orderWithItem(
        Product $product,
        int $quantity,
        int $lineTotal,
        ?Carbon $placedAt = null,
    ): Order {
        $order = Order::factory()->paid()->totals($lineTotal)->create(
            $placedAt !== null ? ['created_at' => $placedAt] : [],
        );

        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'quantity' => $quantity,
            'unit_price' => intdiv($lineTotal, max(1, $quantity)),
            'line_total' => $lineTotal,
        ]);

        return $order;
    }

    /*
    |--------------------------------------------------------------------------
    | Time series
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_day_with_no_orders_is_a_zero_rather_than_a_missing_point(): void
    {
        Order::factory()->paid()->totals(5_000)->create([
            'created_at' => Carbon::now()->subDays(3),
        ]);

        $series = $this->charts()->salesOverview(DateRange::forPeriod(ReportPeriod::Last7Days));

        // Seven days requested, seven points returned — not one.
        $this->assertCount(7, $series);
        $this->assertSame(5_000, collect($series)->sum('value'));
        $this->assertGreaterThan(0, collect($series)->where('value', 0)->count());
    }

    #[Test]
    public function every_bucket_carries_a_label_matching_its_granularity(): void
    {
        $range = DateRange::forPeriod(ReportPeriod::Last7Days);
        $series = $this->charts()->salesOverview($range);

        $this->assertSame(ReportGranularity::Day, $range->granularity);

        foreach ($series as $point) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $point['label']);
        }
    }

    #[Test]
    public function todays_chart_is_bucketed_by_hour(): void
    {
        $range = DateRange::forPeriod(ReportPeriod::Today);

        $this->assertSame(ReportGranularity::Hour, $range->granularity);
        $this->assertCount(24, $this->charts()->salesOverview($range));
    }

    #[Test]
    public function the_orders_series_counts_orders_the_sales_series_excludes(): void
    {
        $range = DateRange::forPeriod(ReportPeriod::Last7Days);

        Order::factory()->paid()->totals(5_000)->create();
        Order::factory()->cancelled()->totals(9_000)->create();

        $sales = collect($this->charts()->salesOverview($range))->sum('value');
        $orders = collect($this->charts()->ordersOverview($range))->sum('value');

        $this->assertSame(5_000, $sales);
        $this->assertSame(2, $orders);
    }

    #[Test]
    public function revenue_by_date_separates_gross_from_refunds(): void
    {
        Order::factory()
            ->paymentStatus(PaymentStatus::PartiallyRefunded)
            ->totals(10_000)
            ->create(['refunded_total' => 3_000]);

        $series = $this->charts()->revenueByDate(DateRange::forPeriod(ReportPeriod::Last7Days));

        $this->assertSame(10_000, collect($series)->sum('gross'));
        $this->assertSame(3_000, collect($series)->sum('refunds'));

        // A flat net line hides whether a quiet month was quiet or merely
        // heavily returned; both components are reported.
        $this->assertSame(7_000, collect($series)->sum('net'));
    }

    /*
    |--------------------------------------------------------------------------
    | Ranked series
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function top_products_rank_by_revenue_not_units(): void
    {
        $sofa = Product::factory()->published()->create(['name' => 'Sofa']);
        $screws = Product::factory()->published()->create(['name' => 'Screws']);

        $this->orderWithItem($sofa, 1, 90_000);
        $this->orderWithItem($screws, 200, 4_000);

        $top = $this->charts()->topProducts(DateRange::forPeriod(ReportPeriod::Last7Days));

        // Two hundred screws must not outrank a sofa on a chart the
        // merchandising team reads as "what matters".
        $this->assertSame('Sofa', $top[0]['name']);
        $this->assertSame(90_000, $top[0]['revenue']);
        $this->assertSame(200, $top[1]['units']);
    }

    #[Test]
    public function top_products_are_limited_to_the_requested_count(): void
    {
        foreach (range(1, 5) as $index) {
            $product = Product::factory()->published()->create(['name' => "Item {$index}"]);
            $this->orderWithItem($product, 1, $index * 1_000);
        }

        $top = $this->charts()->topProducts(DateRange::forPeriod(ReportPeriod::Last7Days), 3);

        $this->assertCount(3, $top);
        $this->assertSame('Item 5', $top[0]['name']);
    }

    #[Test]
    public function top_categories_aggregate_the_products_beneath_them(): void
    {
        $category = Category::factory()->create(['name' => 'Furniture']);

        $chair = Product::factory()->published()->create(['category_id' => $category->id]);
        $table = Product::factory()->published()->create(['category_id' => $category->id]);

        $this->orderWithItem($chair, 1, 6_000);
        $this->orderWithItem($table, 1, 4_000);

        $top = $this->charts()->topCategories(DateRange::forPeriod(ReportPeriod::Last7Days));

        $this->assertSame('Furniture', $top[0]['name']);
        $this->assertSame(10_000, $top[0]['revenue']);
        $this->assertSame(2, $top[0]['units']);
    }

    /*
    |--------------------------------------------------------------------------
    | Distributions
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_payment_method_appears_even_with_no_orders(): void
    {
        Order::factory()->paid()->totals(5_000)->create([
            'payment_method' => PaymentMethod::CashOnDelivery,
        ]);

        $series = $this->charts()->paymentMethods(DateRange::forPeriod(ReportPeriod::Last7Days));

        // A zero is information; an absence is ambiguous between "unused" and
        // "not offered".
        $this->assertCount(count(PaymentMethod::cases()), $series);

        $cod = collect($series)->firstWhere('method', PaymentMethod::CashOnDelivery->value);
        $this->assertSame(5_000, $cod['revenue']);
        $this->assertSame(1, $cod['orders']);
    }

    #[Test]
    public function every_order_status_appears_in_the_distribution(): void
    {
        Order::factory()->count(3)->status(OrderStatus::Pending)->create();
        Order::factory()->delivered()->create();

        $series = $this->charts()->orderStatusDistribution(DateRange::forPeriod(ReportPeriod::Last7Days));

        $this->assertCount(count(OrderStatus::cases()), $series);

        $pending = collect($series)->firstWhere('status', OrderStatus::Pending->value);
        $this->assertSame(3, $pending['orders']);
        $this->assertSame(75.0, $pending['share']);
    }

    #[Test]
    public function shares_are_zero_rather_than_a_division_error_on_an_empty_window(): void
    {
        $series = $this->charts()->orderStatusDistribution(DateRange::forPeriod(ReportPeriod::Today));

        $this->assertCount(count(OrderStatus::cases()), $series);
        $this->assertSame(0.0, $series[0]['share']);
    }

    #[Test]
    public function the_combined_payload_carries_every_chart_the_brief_asks_for(): void
    {
        $charts = $this->charts()->all(DateRange::forPeriod(ReportPeriod::Last7Days));

        foreach ([
            'sales_overview',
            'orders_overview',
            'revenue_by_date',
            'top_products',
            'top_categories',
            'payment_methods',
            'order_status_distribution',
        ] as $key) {
            $this->assertArrayHasKey($key, $charts);
        }
    }
}
