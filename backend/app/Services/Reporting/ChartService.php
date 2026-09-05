<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReportGranularity;
use App\Models\OrderItem;
use App\Support\DateRange;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's chart series.
 *
 * ## Gaps are filled, not omitted
 *
 * `GROUP BY` returns no row for a day with no orders. A chart drawn straight
 * from that result joins the day before to the day after with a single
 * segment, which reads as a flat period rather than as a zero — the most
 * misleading thing a sales chart can do. Every time series here is therefore
 * projected onto the complete set of buckets from {@see DateRange::buckets()},
 * with missing points filled at zero.
 *
 * ## Bucketing happens in the database
 *
 * The alternative — selecting every order in the range and grouping in PHP —
 * transfers a year of rows to build twelve points. Each series below groups by
 * a driver-appropriate date expression (see {@see ReportGranularity})
 * and returns only the buckets.
 *
 * ## Ranked series are bounded
 *
 * "Top products" is `LIMIT`ed in SQL rather than sorted in PHP, so the database
 * returns ten rows regardless of how many products the store sells.
 */
final class ChartService
{
    public function __construct(
        private readonly ReportCache $cache,
        private readonly RevenueScope $scope,
    ) {}

    /**
     * Every chart the dashboard renders, in one cached payload.
     *
     * Assembled together rather than exposed as seven endpoints because a
     * dashboard renders all of them at once: seven requests would each pay the
     * HTTP and auth cost to run one aggregate.
     *
     * @return array<string, mixed>
     */
    public function all(DateRange $range, int $topN = 10): array
    {
        $topN = $this->boundTopN($topN);

        return $this->cache->remember("charts:all:{$topN}", $range, fn (): array => [
            'range' => $range->toArray(),
            'sales_overview' => $this->salesOverview($range),
            'orders_overview' => $this->ordersOverview($range),
            'revenue_by_date' => $this->revenueByDate($range),
            'top_products' => $this->topProducts($range, $topN),
            'top_categories' => $this->topCategories($range, $topN),
            'payment_methods' => $this->paymentMethods($range),
            'order_status_distribution' => $this->orderStatusDistribution($range),
        ]);
    }

    /**
     * Net sales per bucket.
     *
     * The headline chart: what the store actually took, over time.
     *
     * @return array<int, array{label: string, value: int}>
     */
    public function salesOverview(DateRange $range): array
    {
        $bucket = $range->granularity->sqlBucket($this->driver(), 'orders.created_at');

        $rows = $this->scope->revenue($range)
            ->selectRaw("{$bucket} as bucket")
            ->selectRaw(sprintf('COALESCE(SUM(%s), 0) as value', $this->scope->netRevenueExpression()))
            ->groupBy('bucket')
            ->pluck('value', 'bucket');

        return $this->fillSeries($range, $rows->all());
    }

    /**
     * Order count per bucket.
     *
     * Counts every order placed, not only the paid ones — an operations chart
     * showing volume, deliberately a different population from the sales chart
     * above it. See {@see RevenueScope} for why that distinction is kept.
     *
     * @return array<int, array{label: string, value: int}>
     */
    public function ordersOverview(DateRange $range): array
    {
        $bucket = $range->granularity->sqlBucket($this->driver(), 'orders.created_at');

        $rows = $this->scope->orders($range)
            ->selectRaw("{$bucket} as bucket")
            ->selectRaw('COUNT(*) as value')
            ->groupBy('bucket')
            ->pluck('value', 'bucket');

        return $this->fillSeries($range, $rows->all());
    }

    /**
     * Revenue per bucket, broken into gross, refunds, and net.
     *
     * Distinct from {@see salesOverview()} in showing what was refunded rather
     * than only the net line. A month whose net looks flat because heavy sales
     * were offset by heavy returns is a different business situation from a
     * genuinely quiet month, and the net line alone cannot tell them apart.
     *
     * @return array<int, array{label: string, gross: int, refunds: int, net: int}>
     */
    public function revenueByDate(DateRange $range): array
    {
        $bucket = $range->granularity->sqlBucket($this->driver(), 'orders.created_at');

        $rows = $this->scope->revenue($range)
            ->selectRaw("{$bucket} as bucket")
            ->selectRaw('COALESCE(SUM(orders.grand_total), 0) as gross')
            ->selectRaw('COALESCE(SUM(orders.refunded_total), 0) as refunds')
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $series = [];

        foreach ($range->buckets() as $label) {
            $row = $rows->get($label);
            $gross = (int) ($row->gross ?? 0);
            $refunds = (int) ($row->refunds ?? 0);

            $series[] = [
                'label' => $label,
                'gross' => $gross,
                'refunds' => $refunds,
                'net' => $gross - $refunds,
            ];
        }

        return $series;
    }

    /**
     * Best-selling products in the window, by revenue.
     *
     * Ranked on revenue rather than units: a store selling one sofa and two
     * hundred screws should not see the screws at the top of a chart the
     * merchandising team reads as "what matters". Units come back alongside so
     * the client can offer either ordering without a second query.
     *
     * Grouped by `product_id` — the stable identity — while the label comes
     * from the *snapshot* name on the line, so an item reports under the name
     * it was sold under. Grouping by the snapshot itself would split a product
     * renamed mid-period into two bars, neither of them its real total. See
     * ReportService::productSalesQuery() for the same reasoning at length.
     *
     * @return array<int, array<string, mixed>>
     */
    public function topProducts(DateRange $range, int $limit = 10): array
    {
        $limit = $this->boundTopN($limit);

        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.created_at', [$range->from, $range->to])
            ->whereIn('orders.payment_status', $this->scope->paidStatuses())
            ->whereNotIn('orders.status', $this->scope->excludedStatuses())
            ->groupBy('order_items.product_id')
            ->selectRaw('MIN(order_items.product_name) as name')
            ->selectRaw('MIN(order_items.product_sku) as sku')
            ->selectRaw('SUM(order_items.quantity) as units')
            ->selectRaw('SUM(order_items.line_total) as revenue')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'name' => (string) $row->name,
                'sku' => $row->sku !== null ? (string) $row->sku : null,
                'units' => (int) $row->units,
                'revenue' => (int) $row->revenue,
            ])
            ->all();
    }

    /**
     * Best-selling categories in the window, by revenue.
     *
     * Joined through the live `products` table because `order_items` snapshots
     * a product's name but not its category — and unlike a name, a category is
     * a classification the store expects to be able to reorganise without
     * rewriting history. A product moved between categories therefore reports
     * under its current one, which is what a merchandising question means.
     *
     * Lines whose product has since been deleted are excluded by the inner
     * join rather than bucketed under a null category, which would render as an
     * unlabelled slice nobody can act on.
     *
     * @return array<int, array<string, mixed>>
     */
    public function topCategories(DateRange $range, int $limit = 10): array
    {
        $limit = $this->boundTopN($limit);

        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->whereBetween('orders.created_at', [$range->from, $range->to])
            ->whereIn('orders.payment_status', $this->scope->paidStatuses())
            ->whereNotIn('orders.status', $this->scope->excludedStatuses())
            ->groupBy('categories.id', 'categories.name')
            ->selectRaw('categories.name as name')
            ->selectRaw('SUM(order_items.quantity) as units')
            ->selectRaw('SUM(order_items.line_total) as revenue')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'name' => (string) $row->name,
                'units' => (int) $row->units,
                'revenue' => (int) $row->revenue,
            ])
            ->all();
    }

    /**
     * Revenue and order share by payment method.
     *
     * Every configured method appears, including those with no orders in the
     * window — a zero for bKash is information ("nobody used it"), whereas its
     * absence from the chart is ambiguous between that and "not offered".
     *
     * @return array<int, array<string, mixed>>
     */
    public function paymentMethods(DateRange $range): array
    {
        $rows = $this->scope->revenue($range)
            ->groupBy('orders.payment_method')
            ->selectRaw('orders.payment_method as method')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw(sprintf('COALESCE(SUM(%s), 0) as revenue', $this->scope->netRevenueExpression()))
            ->get()
            ->keyBy('method');

        $series = [];

        foreach (PaymentMethod::cases() as $method) {
            $row = $rows->get($method->value);

            $series[] = [
                'method' => $method->value,
                'label' => $method->label(),
                'orders' => (int) ($row->orders ?? 0),
                'revenue' => (int) ($row->revenue ?? 0),
            ];
        }

        // Heaviest first, so the chart's legend order matches its visual order.
        usort($series, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        return $series;
    }

    /**
     * How the window's orders are distributed across the lifecycle.
     *
     * Every status is listed, zeros included, in the enum's own order — a
     * pipeline chart whose categories appear and disappear between refreshes
     * is unreadable, and lifecycle order is more meaningful here than ranking
     * by size.
     *
     * @return array<int, array<string, mixed>>
     */
    public function orderStatusDistribution(DateRange $range): array
    {
        $rows = $this->scope->orders($range)
            ->groupBy('orders.status')
            ->selectRaw('orders.status as status')
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(orders.grand_total), 0) as value')
            ->get()
            ->keyBy('status');

        $total = (int) $rows->sum('orders');

        return array_map(
            function (OrderStatus $status) use ($rows, $total): array {
                $row = $rows->get($status->value);
                $count = (int) ($row->orders ?? 0);

                return [
                    'status' => $status->value,
                    'label' => $status->label(),
                    'orders' => $count,
                    'value' => (int) ($row->value ?? 0),
                    'share' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
                ];
            },
            OrderStatus::cases(),
        );
    }

    /**
     * Project a sparse `bucket => value` map onto the range's complete bucket
     * list, filling absent buckets with zero.
     *
     * @param  array<string, mixed>  $rows
     * @return array<int, array{label: string, value: int}>
     */
    private function fillSeries(DateRange $range, array $rows): array
    {
        $series = [];

        foreach ($range->buckets() as $label) {
            $series[] = [
                'label' => $label,
                'value' => (int) ($rows[$label] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Keep a caller-supplied "top N" inside configured bounds.
     *
     * The value reaches SQL as a `LIMIT`, so it is clamped to an integer range
     * here rather than trusted — and bounded above so one request cannot ask
     * for every product the store sells.
     */
    private function boundTopN(int $limit): int
    {
        $max = (int) config('reporting.limits.max_top_n', 50);

        return max(1, min($limit, $max));
    }

    private function driver(): string
    {
        return DB::connection()->getDriverName();
    }
}
