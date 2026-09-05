<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReportPeriod;
use App\Enums\ReportType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\Reporting\ReportService;
use App\Support\DateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The seven tabular reports.
 *
 * The recurring hazard these tests guard is pagination over grouped queries: a
 * `GROUP BY` report counted naively reports one row per group's *size* rather
 * than the number of groups, producing a paginator that claims hundreds of
 * pages for a twelve-row report. Four of these reports are grouped, so each
 * gets its count asserted.
 */
final class ReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private function reports(): ReportService
    {
        return $this->app->make(ReportService::class);
    }

    private function range(ReportPeriod $period = ReportPeriod::Last30Days): DateRange
    {
        return DateRange::forPeriod($period);
    }

    /*
    |--------------------------------------------------------------------------
    | Shape
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function every_report_returns_rows_matching_its_declared_columns(): void
    {
        Order::factory()->paid()->totals(10_000, 500, 300)->create();
        Product::factory()->published()->create();
        User::factory()->create();

        foreach (ReportType::cases() as $type) {
            $result = $this->reports()->paginate($type, $this->range());

            $this->assertSame($type->columnKeys(), array_column($result['report']['columns'], 'key'));

            foreach ($result['rows'] as $row) {
                // A column added to a query but not to the enum would leak
                // into an export whose header row does not mention it.
                $this->assertSame($type->columnKeys(), array_keys($row), $type->value);
            }
        }
    }

    #[Test]
    public function money_columns_come_back_as_integers_not_driver_strings(): void
    {
        Order::factory()->paid()->totals(10_000)->create();

        $result = $this->reports()->paginate(ReportType::Orders, $this->range());

        $this->assertIsInt($result['rows'][0]['grand_total']);
        $this->assertSame(10_000, $result['rows'][0]['grand_total']);
    }

    /*
    |--------------------------------------------------------------------------
    | Sales and tax
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_sales_report_totals_a_period_per_row(): void
    {
        Order::factory()->paid()->totals(10_000, 500, 300)->create();
        Order::factory()->paid()->totals(20_000, 1_000, 400)->create();

        $result = $this->reports()->paginate(ReportType::Sales, $this->range(ReportPeriod::Today));

        $this->assertCount(1, $result['rows']);

        $row = $result['rows'][0];
        $this->assertSame(2, $row['orders']);
        $this->assertSame(32_200, $row['gross']);
        $this->assertSame(1_500, $row['tax']);
        $this->assertSame(700, $row['shipping']);
    }

    #[Test]
    public function the_tax_report_derives_an_effective_rate_from_the_totals(): void
    {
        // 10% of a 10,000 base.
        Order::factory()->paid()->totals(10_000, 1_000)->create();

        $result = $this->reports()->paginate(ReportType::Tax, $this->range(ReportPeriod::Today));

        $row = $result['rows'][0];
        $this->assertSame(10_000, $row['taxable_base']);
        $this->assertSame(1_000, $row['tax_collected']);

        // Derived rather than averaged from `orders.tax_rate`, so a period
        // containing two rates weights them by value rather than by count.
        $this->assertSame(10.0, $row['effective_rate']);
    }

    #[Test]
    public function an_empty_tax_period_reports_a_zero_rate_rather_than_dividing_by_zero(): void
    {
        Order::factory()->paid()->totals(10_000, 0)->create();

        $result = $this->reports()->paginate(ReportType::Tax, $this->range(ReportPeriod::Today));

        $this->assertSame(0.0, $result['rows'][0]['effective_rate']);
    }

    /*
    |--------------------------------------------------------------------------
    | Orders
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_order_report_counts_items_without_multiplying_the_order_row(): void
    {
        $order = Order::factory()->paid()->totals(10_000)->create();

        OrderItem::factory()->for($order)->create(['quantity' => 2]);
        OrderItem::factory()->for($order)->create(['quantity' => 3]);

        $result = $this->reports()->paginate(ReportType::Orders, $this->range());

        // One order, not two — a join to order_items would have duplicated it
        // and doubled every money column.
        $this->assertCount(1, $result['rows']);
        $this->assertSame(5, $result['rows'][0]['items']);
        $this->assertSame(10_000, $result['rows'][0]['grand_total']);
    }

    #[Test]
    public function the_order_report_can_be_filtered_by_status(): void
    {
        Order::factory()->delivered()->totals(10_000)->create();
        Order::factory()->cancelled()->totals(20_000)->create();

        $result = $this->reports()->paginate(
            ReportType::Orders,
            $this->range(),
            ['status' => OrderStatus::Cancelled->value],
        );

        $this->assertCount(1, $result['rows']);
        $this->assertSame(20_000, $result['rows'][0]['grand_total']);
    }

    #[Test]
    public function the_order_report_can_be_searched_by_customer_and_number(): void
    {
        Order::factory()->paid()->totals(10_000)->create(['customer_name' => 'Ada Lovelace']);
        Order::factory()->paid()->totals(20_000)->create(['customer_name' => 'Grace Hopper']);

        $result = $this->reports()->paginate(ReportType::Orders, $this->range(), ['search' => 'Ada']);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('Ada Lovelace', $result['rows'][0]['customer_name']);
    }

    #[Test]
    public function a_search_wildcard_is_matched_literally(): void
    {
        Order::factory()->paid()->totals(10_000)->create(['customer_name' => '50% Off Ltd']);
        Order::factory()->paid()->totals(20_000)->create(['customer_name' => 'Ordinary Ltd']);

        // Unescaped, "%" would match every row rather than the one company
        // with it in their name.
        $result = $this->reports()->paginate(ReportType::Orders, $this->range(), ['search' => '50%']);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('50% Off Ltd', $result['rows'][0]['customer_name']);
    }

    /*
    |--------------------------------------------------------------------------
    | Product sales, customers, payments, inventory
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function product_sales_count_one_order_per_order_not_per_line(): void
    {
        $product = Product::factory()->published()->create(['name' => 'Widget']);
        $order = Order::factory()->paid()->totals(10_000)->create();

        // The same product on two lines of one order.
        foreach ([2, 3] as $quantity) {
            OrderItem::factory()->for($order)->create([
                'product_id' => $product->id,
                'product_name' => 'Widget',
                'product_sku' => 'W-1',
                'quantity' => $quantity,
                'unit_price' => 1_000,
                'line_total' => 1_000 * $quantity,
            ]);
        }

        $result = $this->reports()->paginate(ReportType::ProductSales, $this->range());

        $this->assertCount(1, $result['rows']);
        $this->assertSame(1, $result['rows'][0]['orders']);
        $this->assertSame(5, $result['rows'][0]['units']);
        $this->assertSame(5_000, $result['rows'][0]['revenue']);
    }

    #[Test]
    public function the_customer_report_keeps_customers_who_never_ordered(): void
    {
        $buyer = User::factory()->create(['name' => 'Buyer']);
        User::factory()->create(['name' => 'Browser']);

        Order::factory()->paid()->totals(10_000)->create(['user_id' => $buyer->id]);

        $result = $this->reports()->paginate(ReportType::Customers, $this->range());

        // A dormant-account list is one of the things this report is for; an
        // inner join would hide exactly those.
        $this->assertCount(2, $result['rows']);

        $names = array_column($result['rows'], 'name');
        $this->assertContains('Browser', $names);

        $browser = collect($result['rows'])->firstWhere('name', 'Browser');
        $this->assertSame(0, $browser['orders']);
        $this->assertSame(0, $browser['lifetime_value']);
    }

    #[Test]
    public function the_customer_report_derives_an_average_order_value(): void
    {
        $buyer = User::factory()->create(['name' => 'Buyer']);

        Order::factory()->paid()->totals(10_000)->create(['user_id' => $buyer->id]);
        Order::factory()->paid()->totals(20_000)->create(['user_id' => $buyer->id]);

        $result = $this->reports()->paginate(ReportType::Customers, $this->range());
        $row = collect($result['rows'])->firstWhere('name', 'Buyer');

        $this->assertSame(2, $row['orders']);
        $this->assertSame(30_000, $row['lifetime_value']);
        $this->assertSame(15_000, $row['average_order_value']);
    }

    #[Test]
    public function the_payment_report_includes_failed_attempts(): void
    {
        $order = Order::factory()->paid()->totals(10_000)->create();

        Payment::factory()->for($order)->create(['status' => PaymentStatus::Paid, 'amount' => 10_000]);
        Payment::factory()->for($order)->create(['status' => PaymentStatus::Failed, 'amount' => 10_000]);

        $result = $this->reports()->paginate(ReportType::Payments, $this->range());

        // A report used to investigate a gateway's failure rate is useless if
        // it hides the failures.
        $this->assertCount(2, $result['rows']);
    }

    #[Test]
    public function the_inventory_report_labels_each_stock_state(): void
    {
        Product::factory()->published()->create(['name' => 'Plenty', 'stock' => 90, 'low_stock_threshold' => 5]);
        Product::factory()->published()->create(['name' => 'Low', 'stock' => 3, 'low_stock_threshold' => 5]);
        Product::factory()->published()->create(['name' => 'Empty', 'stock' => 0, 'low_stock_threshold' => 5]);

        $result = $this->reports()->paginate(ReportType::Inventory, $this->range());
        $rows = collect($result['rows'])->keyBy('name');

        $this->assertSame('In stock', $rows['Plenty']['stock_state']);
        $this->assertSame('Low stock', $rows['Low']['stock_state']);
        $this->assertSame('Out of stock', $rows['Empty']['stock_state']);
    }

    #[Test]
    public function the_inventory_report_ignores_the_date_range(): void
    {
        Product::factory()->published()->create([
            'stock' => 10,
            'created_at' => Carbon::now()->subYears(3),
        ]);

        // Stock is a present-tense fact; "stock levels last week" is not a
        // question the warehouse is asking.
        $result = $this->reports()->paginate(ReportType::Inventory, $this->range(ReportPeriod::Today));

        $this->assertCount(1, $result['rows']);
        $this->assertNull($result['range']);
    }

    #[Test]
    public function the_inventory_report_can_be_filtered_to_low_stock(): void
    {
        Product::factory()->published()->create(['stock' => 90, 'low_stock_threshold' => 5]);
        Product::factory()->published()->create(['stock' => 3, 'low_stock_threshold' => 5]);

        $result = $this->reports()->paginate(ReportType::Inventory, $this->range(), ['stock_state' => 'low']);

        $this->assertCount(1, $result['rows']);
        $this->assertSame(3, $result['rows'][0]['stock']);
    }

    /*
    |--------------------------------------------------------------------------
    | Pagination and totals
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_grouped_report_counts_its_groups_not_the_rows_inside_them(): void
    {
        // Three products, each sold across several order lines. A naive
        // count() on this GROUP BY would return the size of one group.
        foreach (['A', 'B', 'C'] as $name) {
            $product = Product::factory()->published()->create(['name' => $name]);

            foreach (range(1, 4) as $ignored) {
                $order = Order::factory()->paid()->totals(1_000)->create();
                OrderItem::factory()->for($order)->create([
                    'product_id' => $product->id,
                    'product_name' => $name,
                    // Left to the factory's random value on purpose: the report
                    // groups by product identity, so lines of one product must
                    // aggregate into a single row even when their snapshot SKUs
                    // differ — which is what happens after a product is re-SKU'd.
                    'quantity' => 1,
                    'unit_price' => 1_000,
                    'line_total' => 1_000,
                ]);
            }
        }

        $result = $this->reports()->paginate(ReportType::ProductSales, $this->range());

        $this->assertSame(3, $result['meta']['total']);
        $this->assertSame(1, $result['meta']['last_page']);
    }

    #[Test]
    public function paging_returns_a_different_slice_each_time(): void
    {
        Order::factory()->count(5)->paid()->totals(10_000)->create();

        $first = $this->reports()->paginate(ReportType::Orders, $this->range(), [], 2, 1);
        $second = $this->reports()->paginate(ReportType::Orders, $this->range(), [], 2, 2);

        $this->assertCount(2, $first['rows']);
        $this->assertCount(2, $second['rows']);
        $this->assertSame(5, $first['meta']['total']);
        $this->assertSame(3, $first['meta']['last_page']);

        $this->assertNotSame(
            $first['rows'][0]['order_number'],
            $second['rows'][0]['order_number'],
        );
    }

    #[Test]
    public function totals_cover_the_whole_report_not_the_current_page(): void
    {
        Order::factory()->count(5)->paid()->totals(10_000)->create();

        $page = $this->reports()->paginate(ReportType::Orders, $this->range(), [], 2, 1);

        // A footer showing the page's totals changes as you page through it,
        // which is worse than showing nothing.
        $this->assertCount(2, $page['rows']);
        $this->assertSame(50_000, $page['totals']['grand_total']);
    }

    #[Test]
    public function totals_respect_the_active_filters(): void
    {
        Order::factory()->delivered()->totals(10_000)->create();
        Order::factory()->cancelled()->totals(90_000)->create();

        $page = $this->reports()->paginate(
            ReportType::Orders,
            $this->range(),
            ['status' => OrderStatus::Delivered->value],
        );

        $this->assertSame(10_000, $page['totals']['grand_total']);
    }

    #[Test]
    public function the_cursor_streams_the_same_rows_the_table_shows(): void
    {
        Order::factory()->count(3)->paid()->totals(10_000)->create();

        $streamed = $this->reports()->cursor(ReportType::Orders, $this->range())->all();

        $this->assertCount(3, $streamed);
        $this->assertSame(
            ReportType::Orders->columnKeys(),
            array_keys($streamed[0]),
        );
    }
}
