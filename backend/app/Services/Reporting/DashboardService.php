<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Enums\ReportPeriod;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\DateRange;

/**
 * The dashboard's headline metrics.
 *
 * ## The rule that shapes this class: one query per figure, at most
 *
 * A dashboard tile is cheap to add and expensive to compute. Ten tiles written
 * naively are ten `SELECT COUNT(*)` round trips plus ten more for their
 * period-on-period comparisons — twenty queries before a single chart renders,
 * on a page staff keep open all day.
 *
 * Two things prevent that here:
 *
 *  - **Conditional aggregation.** The four order-status counts are one query
 *    with four `SUM(CASE WHEN ...)` columns, not four `COUNT` queries against
 *    the same rows. Same for sales and order totals, which come back from one
 *    pass over the window.
 *  - **Caching the whole payload.** {@see metrics()} caches its complete result
 *    under one key, so a repeat load inside the TTL costs nothing at all. See
 *    {@see ReportCache} for why invalidation is wholesale rather than targeted.
 *
 * ## Money is never formatted here
 *
 * Every figure returned is an integer in minor units, exactly as stored. The
 * resource layer formats for display. A service that returned "$1,234.50" would
 * force the frontend to parse it back to do arithmetic, and would bake the
 * store's current currency symbol into a cached value that outlives a change to
 * it.
 */
final class DashboardService
{
    /**
     * Per-request memo for {@see liveStatusCounts()}, which is read three times
     * while one payload is assembled.
     *
     * @var array{pending: int, delivered: int, cancelled: int}|null
     */
    private ?array $statusCounts = null;

    public function __construct(
        private readonly ReportCache $cache,
        private readonly RevenueScope $scope,
    ) {}

    /**
     * Every headline metric, with period-on-period comparison where meaningful.
     *
     * @return array<string, mixed>
     */
    public function metrics(DateRange $range): array
    {
        return $this->cache->remember('dashboard:metrics', $range, function () use ($range): array {
            $current = $this->windowTotals($range);
            $previous = $this->windowTotals($range->previous());

            return [
                'range' => $range->toArray(),

                'sales' => [
                    // Scoped to the selected range, whatever it is.
                    'period' => $current['net_sales'],
                    'period_previous' => $previous['net_sales'],
                    'period_change' => $this->percentageChange($previous['net_sales'], $current['net_sales']),

                    /*
                     * Today and this month are fixed windows, not the selected
                     * range — the brief asks for them by name, and a tile
                     * labelled "Today's sales" must mean today however the
                     * filter above it is set.
                     */
                    'today' => $this->salesForPeriod(ReportPeriod::Today),
                    'month' => $this->salesForPeriod(ReportPeriod::ThisMonth),
                    'total' => $this->lifetimeSales(),
                ],

                'orders' => [
                    'period' => $current['order_count'],
                    'period_previous' => $previous['order_count'],
                    'period_change' => $this->percentageChange($previous['order_count'], $current['order_count']),

                    'total' => $this->lifetimeOrderCount(),

                    /*
                     * Status counts are *live*, not range-scoped: "pending
                     * orders" is a work queue asking what needs attention now,
                     * and an operator filtering the dashboard to last March
                     * still needs today's backlog.
                     */
                    'pending' => $this->liveStatusCounts()['pending'],
                    'delivered' => $this->liveStatusCounts()['delivered'],
                    'cancelled' => $this->liveStatusCounts()['cancelled'],
                ],

                'customers' => [
                    'total' => $this->totalCustomers(),
                    'new_in_period' => $this->newCustomers($range),
                ],

                'products' => [
                    'total' => $this->totalProducts(),
                    'low_stock' => $this->lowStockCount(),
                    'out_of_stock' => $this->outOfStockCount(),
                ],

                'average_order_value' => $current['order_count'] > 0
                    ? (int) round($current['net_sales'] / $current['order_count'])
                    : 0,
            ];
        });
    }

    /**
     * Net sales and order count for a window, in a single pass.
     *
     * Both figures come from one query over the same rows because they share a
     * scan: splitting them would read the window twice to answer two questions
     * about it.
     *
     * Note the two different populations in one statement — the count is over
     * every order placed, while the sum is over only those that settled. That
     * is the distinction {@see RevenueScope} exists to keep, expressed here as
     * a `CASE` rather than as a second query.
     *
     * @return array{net_sales: int, order_count: int}
     */
    private function windowTotals(DateRange $range): array
    {
        $paid = $this->scope->paidStatuses();
        $excluded = $this->scope->excludedStatuses();

        /*
         * The revenue predicate is built from the configured status lists.
         * An empty list becomes a constant rather than `IN ()`, which is a
         * syntax error — and must become the right constant: no paid statuses
         * means nothing counts as revenue (0 = 1), while no excluded statuses
         * means nothing is disqualified (1 = 1). Collapsing both to the same
         * literal would silently invert one of them.
         */
        $paidPredicate = $paid === []
            ? '0 = 1'
            : sprintf('orders.payment_status IN (%s)', $this->placeholders($paid));

        $excludedPredicate = $excluded === []
            ? '1 = 1'
            : sprintf('orders.status NOT IN (%s)', $this->placeholders($excluded));

        $row = Order::query()
            ->whereBetween('orders.created_at', [$range->from, $range->to])
            ->selectRaw(
                sprintf(
                    'COUNT(*) as order_count,
                     COALESCE(SUM(CASE WHEN %s AND %s THEN %s ELSE 0 END), 0) as net_sales',
                    $paidPredicate,
                    $excludedPredicate,
                    $this->scope->netRevenueExpression(),
                ),
                [...$paid, ...$excluded],
            )
            ->first();

        return [
            'net_sales' => (int) ($row->net_sales ?? 0),
            'order_count' => (int) ($row->order_count ?? 0),
        ];
    }

    /**
     * Net sales for a named preset, cached independently of the selected range.
     *
     * "Today" and "This month" appear on the dashboard regardless of the
     * filter, so they get their own cache entries — otherwise every distinct
     * range an admin selected would recompute the same two figures.
     */
    private function salesForPeriod(ReportPeriod $period): int
    {
        $window = DateRange::forPeriod($period);

        /*
         * Cast on the way out, not only inside the closure.
         *
         * The array cache store round-trips values through serialisation and
         * hands back exactly what went in, but Redis returns everything as a
         * string — so a hit returns "12345" where a miss returned 12345, and
         * the declared `int` return type fails only once the entry is warm.
         * That makes it a bug that never appears in a fresh test run and
         * always appears in production a minute after deploy.
         */
        return $this->cache->rememberIntFor(
            'dashboard:sales:'.$period->value,
            $window,
            fn (): int => $this->windowTotals($window)['net_sales'],
        );
    }

    /**
     * All-time net sales.
     *
     * No date predicate, so this is the one genuinely expensive aggregate here
     * — it touches every settled order the store has ever taken. It is held at
     * the live TTL rather than computed per request, and the index on
     * (payment_status, created_at) keeps it to a range scan over the paid rows
     * rather than a full table scan.
     */
    private function lifetimeSales(): int
    {
        return $this->cache->rememberInt('dashboard:sales:lifetime', function (): int {
            $row = $this->scope->applyRevenueFilter(Order::query())
                ->selectRaw(sprintf('COALESCE(SUM(%s), 0) as total', $this->scope->netRevenueExpression()))
                ->first();

            return (int) ($row->total ?? 0);
        });
    }

    private function lifetimeOrderCount(): int
    {
        return $this->cache->rememberInt(
            'dashboard:orders:lifetime',
            fn (): int => Order::query()->count(),
        );
    }

    /**
     * Current counts for the statuses the dashboard surfaces as work queues.
     *
     * One query, three columns. Memoised on the *instance* because {@see
     * metrics()} reads it three times while assembling its payload and a cache
     * hit is still a Redis round trip — but not statically, which would outlive
     * the request in a queue worker or Octane process and serve one dashboard's
     * counts to the next.
     *
     * @return array{pending: int, delivered: int, cancelled: int}
     */
    private function liveStatusCounts(): array
    {
        if ($this->statusCounts !== null) {
            return $this->statusCounts;
        }

        /** @var array{pending: int, delivered: int, cancelled: int} $counts */
        $counts = $this->cache->rememberLive('dashboard:status-counts', function (): array {
            $row = Order::query()
                ->selectRaw(
                    'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending,
                     SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as delivered,
                     SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled',
                    [
                        OrderStatus::Pending->value,
                        OrderStatus::Delivered->value,
                        OrderStatus::Cancelled->value,
                    ],
                )
                ->first();

            return [
                'pending' => (int) ($row->pending ?? 0),
                'delivered' => (int) ($row->delivered ?? 0),
                'cancelled' => (int) ($row->cancelled ?? 0),
            ];
        });

        return $this->statusCounts = $counts;
    }

    private function totalCustomers(): int
    {
        return $this->cache->rememberInt(
            'dashboard:customers:total',
            fn (): int => User::query()->count(),
        );
    }

    private function newCustomers(DateRange $range): int
    {
        return $this->cache->rememberIntFor(
            'dashboard:customers:new',
            $range,
            fn (): int => User::query()
                ->whereBetween('created_at', [$range->from, $range->to])
                ->count(),
        );
    }

    private function totalProducts(): int
    {
        return $this->cache->rememberInt(
            'dashboard:products:total',
            fn (): int => Product::query()->where('status', ProductStatus::Published->value)->count(),
        );
    }

    private function lowStockCount(): int
    {
        return $this->cache->rememberInt(
            'dashboard:products:low-stock',
            fn (): int => Product::query()->lowStock()->count(),
        );
    }

    private function outOfStockCount(): int
    {
        return $this->cache->rememberInt(
            'dashboard:products:out-of-stock',
            fn (): int => Product::query()->outOfStock()->count(),
        );
    }

    /**
     * The products needing a reorder, for the dashboard's alert panel.
     *
     * Bounded and ordered by scarcity: the point of the panel is the handful of
     * lines closest to running out, not an inventory listing, which is what the
     * inventory report is for.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lowStockProducts(int $limit = 10): array
    {
        $limit = max(1, min($limit, 50));

        return $this->cache->rememberLive(
            'dashboard:low-stock-products:'.$limit,
            fn (): array => Product::query()
                ->lowStock()
                ->orderBy('stock')
                ->limit($limit)
                ->get(['id', 'uuid', 'name', 'sku', 'stock', 'low_stock_threshold'])
                ->map(fn (Product $product): array => [
                    'id' => $product->uuid,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'stock' => $product->stock,
                    'threshold' => $product->low_stock_threshold,
                ])
                ->all(),
        );
    }

    /**
     * Period-on-period movement, as a percentage to one decimal place.
     *
     * Growth from zero is reported as null rather than as an infinite or
     * 100% rise. There is no meaningful percentage increase from nothing, and
     * a tile reading "+100%" on the store's first sale is worse than one that
     * simply shows no comparison.
     */
    private function percentageChange(int $previous, int $current): ?float
    {
        if ($previous === 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * A comma-separated run of `?` placeholders for an `IN` clause.
     *
     * The values themselves are always bound, never interpolated — this only
     * produces the punctuation. Callers guard the empty case before calling,
     * because the correct substitute for `IN ()` differs by predicate.
     *
     * @param  array<int, string>  $values
     */
    private function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
