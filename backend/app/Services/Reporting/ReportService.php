<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\ReportType;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * The seven tabular reports, as query builders.
 *
 * ## Every report is a builder, not a result set
 *
 * Each `*Query()` method returns an unexecuted query. That is what lets one
 * definition serve both surfaces that consume it:
 *
 *  - the panel calls {@see paginate()}, which adds `LIMIT`/`OFFSET`;
 *  - an export calls {@see cursor()}, which streams the whole thing row by row
 *    without materialising it.
 *
 * If the reports returned arrays instead, exporting would mean loading fifty
 * thousand rows into memory to write them straight back out again.
 *
 * ## Search and filters are applied inside the query
 *
 * Filtering a collection in PHP after the fact reads the whole table to return
 * twenty rows, and — worse — paginates the unfiltered set, so page two of a
 * search shows results that do not match it. Both go into SQL.
 *
 * ## Aggregates are grouped in the database
 *
 * The customer report's "lifetime value" is a `SUM` in a grouped join, not a
 * per-customer query in a loop. The N+1 version is invisible on a seed database
 * and fatal on a real one.
 */
final class ReportService
{
    public function __construct(
        private readonly RevenueScope $scope,
        private readonly ReportCache $cache,
    ) {}

    /**
     * Build the query behind a report.
     *
     * @param  array<string, mixed>  $filters
     */
    public function query(ReportType $type, DateRange $range, array $filters = []): BuilderContract
    {
        return match ($type) {
            ReportType::Sales => $this->salesQuery($range),
            ReportType::Orders => $this->ordersQuery($range, $filters),
            ReportType::ProductSales => $this->productSalesQuery($range, $filters),
            ReportType::Customers => $this->customersQuery($range, $filters),
            ReportType::Payments => $this->paymentsQuery($range, $filters),
            ReportType::Tax => $this->taxQuery($range),
            ReportType::Inventory => $this->inventoryQuery($filters),
        };
    }

    /**
     * A page of a report, shaped for the panel's table.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function paginate(ReportType $type, DateRange $range, array $filters = [], int $perPage = 25, int $page = 1): array
    {
        $perPage = $this->boundPerPage($perPage);
        $page = max(1, $page);

        $query = $this->query($type, $range, $filters);

        /*
         * Counted through a subquery rather than by cloning and re-aggregating.
         * Four of these reports are `GROUP BY` queries, where a naive
         * `->count()` returns one row per group instead of the number of
         * groups — a paginator that then reports 900 pages of a 12-row report.
         */
        $total = $this->countRows($query);

        $rows = $query
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($row): array => $this->normaliseRow($type, (array) $row))
            ->all();

        return [
            'report' => [
                'type' => $type->value,
                'label' => $type->label(),
                'columns' => $type->columns(),
            ],
            'range' => $type->isDateScoped() ? $range->toArray() : null,
            'rows' => $rows,
            'totals' => $this->totalsFor($type, $range, $filters),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
            ],
        ];
    }

    /**
     * Stream every row of a report, for export.
     *
     * `cursor()` keeps one row in memory at a time. The row cap is enforced
     * here rather than left to the exporter so a range that would produce a
     * hundred thousand rows fails immediately with an explanation, rather than
     * after two minutes of streaming.
     *
     * @param  array<string, mixed>  $filters
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function cursor(ReportType $type, DateRange $range, array $filters = []): LazyCollection
    {
        $query = $this->query($type, $range, $filters);
        $max = (int) config('reporting.limits.max_export_rows', 50000);

        return $query
            ->limit($max)
            ->cursor()
            ->map(fn ($row): array => $this->normaliseRow($type, (array) $row));
    }

    /**
     * How many rows an export of this report would contain.
     */
    public function rowCount(ReportType $type, DateRange $range, array $filters = []): int
    {
        return $this->countRows($this->query($type, $range, $filters));
    }

    /*
    |--------------------------------------------------------------------------
    | Report definitions
    |--------------------------------------------------------------------------
    */

    /**
     * Revenue totalled per bucket over the range.
     *
     * The one report whose rows are periods rather than records: a finance
     * summary answering "what did each week take", with the components that
     * make up the net figure broken out beside it so the arithmetic is
     * auditable rather than a single number to be trusted.
     */
    private function salesQuery(DateRange $range): BuilderContract
    {
        $bucket = $range->granularity->sqlBucket(DB::connection()->getDriverName(), 'orders.created_at');

        return $this->scope->revenue($range)
            ->toBase()
            ->selectRaw("{$bucket} as period")
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(orders.grand_total), 0) as gross')
            ->selectRaw('COALESCE(SUM(orders.discount_total), 0) as discounts')
            ->selectRaw('COALESCE(SUM(orders.refunded_total), 0) as refunds')
            ->selectRaw('COALESCE(SUM(orders.tax_total), 0) as tax')
            ->selectRaw('COALESCE(SUM(orders.shipping_total), 0) as shipping')
            ->selectRaw(sprintf('COALESCE(SUM(%s), 0) as net', $this->scope->netRevenueExpression()))
            ->groupBy('period')
            ->orderBy('period');
    }

    /**
     * Every order placed in the range.
     *
     * Unlike the sales report this counts all orders, cancellations included —
     * an operations record rather than a financial one.
     *
     * The item count comes from a correlated subquery rather than a join: a
     * join to `order_items` would multiply each order row by its line count and
     * require grouping every money column back down, which is both slower and
     * far easier to get subtly wrong.
     *
     * @param  array<string, mixed>  $filters
     */
    private function ordersQuery(DateRange $range, array $filters): BuilderContract
    {
        $query = $this->scope->orders($range)
            ->toBase()
            ->select([
                'orders.order_number',
                'orders.placed_at',
                'orders.created_at',
                'orders.customer_name',
                'orders.customer_email',
                'orders.status',
                'orders.payment_status',
                'orders.payment_method',
                'orders.subtotal',
                'orders.discount_total',
                'orders.tax_total',
                'orders.shipping_total',
                'orders.grand_total',
                'orders.refunded_total',
            ])
            ->selectSub(
                OrderItem::query()
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('order_items.order_id', 'orders.id'),
                'items',
            )
            ->orderByDesc('orders.created_at');

        $this->applyOrderFilters($query, $filters);

        if (($term = $this->searchTerm($filters)) !== null) {
            $query->where(function (QueryBuilder $inner) use ($term): void {
                $inner->where('orders.order_number', 'like', $term)
                    ->orWhere('orders.customer_name', 'like', $term)
                    ->orWhere('orders.customer_email', 'like', $term);
            });
        }

        return $query;
    }

    /**
     * Units and revenue per product.
     *
     * ## Grouped by product identity, labelled by snapshot
     *
     * The grain is `product_id`, not the snapshot name and SKU stored on the
     * line. Those change: a product renamed or re-SKU'd between two sales would
     * split into two rows under a snapshot grouping, so a report meant to
     * answer "how did this product sell this quarter" would show it twice with
     * neither figure correct.
     *
     * The label still comes from the snapshot — `MIN()` picks one deterministic
     * name per group, which is required by `ONLY_FULL_GROUP_BY` and reads as
     * the name it was sold under rather than whatever the catalog says today.
     *
     * Lines whose product was since deleted keep a null `product_id`; they
     * group together into one "deleted products" row rather than vanishing,
     * because their revenue was real.
     *
     * `COUNT(DISTINCT order_id)` rather than `COUNT(*)`: two lines of the same
     * product on one order is one order, not two.
     *
     * @param  array<string, mixed>  $filters
     */
    private function productSalesQuery(DateRange $range, array $filters): BuilderContract
    {
        $query = OrderItem::query()
            ->toBase()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.created_at', [$range->from, $range->to])
            ->whereIn('orders.payment_status', $this->scope->paidStatuses())
            ->whereNotIn('orders.status', $this->scope->excludedStatuses())
            ->groupBy('order_items.product_id')
            ->selectRaw('MIN(order_items.product_name) as name')
            ->selectRaw('MIN(order_items.product_sku) as sku')
            ->selectRaw('COUNT(DISTINCT order_items.order_id) as orders')
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as units')
            ->selectRaw('COALESCE(SUM(order_items.line_total + order_items.discount_total), 0) as gross')
            ->selectRaw('COALESCE(SUM(order_items.discount_total), 0) as discounts')
            ->selectRaw('COALESCE(SUM(order_items.line_total), 0) as revenue')
            ->orderByDesc('revenue');

        if (($term = $this->searchTerm($filters)) !== null) {
            $query->where(function (QueryBuilder $inner) use ($term): void {
                $inner->where('order_items.product_name', 'like', $term)
                    ->orWhere('order_items.product_sku', 'like', $term);
            });
        }

        return $query;
    }

    /**
     * Registered customers with their purchasing history.
     *
     * Aggregated with a left join so a customer who has never ordered still
     * appears, at zero — a marketing list of dormant accounts is one of the
     * things this report is for, and an inner join would hide exactly those.
     *
     * Guest orders are absent by construction: they have no `user_id` to group
     * by. The order report is where guest activity is visible.
     *
     * @param  array<string, mixed>  $filters
     */
    private function customersQuery(DateRange $range, array $filters): BuilderContract
    {
        $paid = $this->scope->paidStatuses();
        $excluded = $this->scope->excludedStatuses();

        /*
         * The date range constrains the *orders* joined in, not which customers
         * are listed — "customers, with what they bought in Q3" rather than
         * "customers who registered in Q3". Putting these predicates in the
         * JOIN condition instead of WHERE is what preserves that: in WHERE they
         * would discard the null-order rows the left join exists to keep.
         */
        $query = User::query()
            ->toBase()
            ->leftJoin('orders', function ($join) use ($range, $paid, $excluded): void {
                $join->on('orders.user_id', '=', 'users.id')
                    ->whereBetween('orders.created_at', [$range->from, $range->to])
                    ->whereIn('orders.payment_status', $paid)
                    ->whereNotIn('orders.status', $excluded);
            })
            ->groupBy('users.id', 'users.name', 'users.email', 'users.created_at')
            ->select([
                'users.name',
                'users.email',
                'users.created_at as registered_at',
            ])
            ->selectRaw('COUNT(orders.id) as orders')
            ->selectRaw(sprintf('COALESCE(SUM(%s), 0) as lifetime_value', $this->scope->netRevenueExpression()))
            ->selectRaw('MAX(orders.created_at) as last_order_at')
            ->selectSub(
                OrderItem::query()
                    ->selectRaw('COALESCE(SUM(order_items.quantity), 0)')
                    ->join('orders as o2', 'o2.id', '=', 'order_items.order_id')
                    ->whereColumn('o2.user_id', 'users.id')
                    ->whereBetween('o2.created_at', [$range->from, $range->to]),
                'units',
            )
            ->orderByDesc('lifetime_value');

        if (($term = $this->searchTerm($filters)) !== null) {
            $query->where(function (QueryBuilder $inner) use ($term): void {
                $inner->where('users.name', 'like', $term)
                    ->orWhere('users.email', 'like', $term);
            });
        }

        return $query;
    }

    /**
     * Individual payment attempts.
     *
     * Every attempt, not only the successful ones — a report used to
     * investigate a gateway's failure rate is useless if it hides the
     * failures.
     *
     * @param  array<string, mixed>  $filters
     */
    private function paymentsQuery(DateRange $range, array $filters): BuilderContract
    {
        $query = Payment::query()
            ->toBase()
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->whereBetween('payments.created_at', [$range->from, $range->to])
            ->select([
                'payments.created_at',
                'orders.order_number',
                'payments.method',
                'payments.gateway',
                'payments.transaction_reference',
                'payments.status',
                'payments.amount',
            ])
            ->orderByDesc('payments.created_at');

        if (is_string($filters['status'] ?? null) && $filters['status'] !== '') {
            $query->where('payments.status', $filters['status']);
        }

        if (is_string($filters['gateway'] ?? null) && $filters['gateway'] !== '') {
            $query->where('payments.gateway', $filters['gateway']);
        }

        if (is_string($filters['method'] ?? null) && $filters['method'] !== '') {
            $query->where('payments.method', $filters['method']);
        }

        if (($term = $this->searchTerm($filters)) !== null) {
            $query->where(function (QueryBuilder $inner) use ($term): void {
                $inner->where('payments.transaction_reference', 'like', $term)
                    ->orWhere('orders.order_number', 'like', $term);
            });
        }

        return $query;
    }

    /**
     * Tax collected per period.
     *
     * The effective rate is derived from the totals rather than read from
     * `orders.tax_rate`, because a period will contain orders charged at
     * different rates if the rate changed mid-period. Averaging the stored
     * rates would weight a £5 order equally with a £5,000 one; dividing
     * collected tax by the taxable base gives the rate actually realised.
     */
    private function taxQuery(DateRange $range): BuilderContract
    {
        $bucket = $range->granularity->sqlBucket(DB::connection()->getDriverName(), 'orders.created_at');

        return $this->scope->revenue($range)
            ->toBase()
            ->selectRaw("{$bucket} as period")
            ->selectRaw('COUNT(*) as orders')
            ->selectRaw('COALESCE(SUM(orders.subtotal - orders.discount_total), 0) as taxable_base')
            ->selectRaw('COALESCE(SUM(orders.tax_total), 0) as tax_collected')
            ->groupBy('period')
            ->orderBy('period');
    }

    /**
     * Current stock, with valuation.
     *
     * Not date-scoped — see {@see ReportType::isDateScoped()}. Stock value is
     * computed at the product's current price rather than at cost, because this
     * schema has no cost column; the column is labelled "unit price"
     * accordingly rather than implying a margin figure it cannot support.
     *
     * @param  array<string, mixed>  $filters
     */
    private function inventoryQuery(array $filters): BuilderContract
    {
        $query = Product::query()
            ->toBase()
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->select([
                'products.name',
                'products.sku',
                'products.status',
                'products.stock',
                'products.low_stock_threshold as threshold',
                'categories.name as category',
            ])
            ->selectRaw('COALESCE(products.discount_price, products.price) as unit_cost')
            ->selectRaw('COALESCE(products.discount_price, products.price) * products.stock as stock_value')
            ->orderBy('products.stock');

        if (is_string($filters['status'] ?? null) && $filters['status'] !== '') {
            $query->where('products.status', $filters['status']);
        }

        /*
         * The stock-state filter mirrors Product's own scopes rather than
         * re-deriving the thresholds, so "low stock" means the same thing here
         * as it does on the dashboard tile and in the catalog.
         */
        $state = is_string($filters['stock_state'] ?? null) ? $filters['stock_state'] : null;

        if ($state === 'low') {
            $query->whereColumn('products.stock', '<=', 'products.low_stock_threshold')
                ->where('products.stock', '>', 0);
        } elseif ($state === 'out') {
            $query->where('products.stock', '<=', 0);
        } elseif ($state === 'in') {
            $query->whereColumn('products.stock', '>', 'products.low_stock_threshold');
        }

        if (($term = $this->searchTerm($filters)) !== null) {
            $query->where(function (QueryBuilder $inner) use ($term): void {
                $inner->where('products.name', 'like', $term)
                    ->orWhere('products.sku', 'like', $term);
            });
        }

        return $query;
    }

    /*
    |--------------------------------------------------------------------------
    | Shared helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyOrderFilters(QueryBuilder $query, array $filters): void
    {
        if (is_string($filters['status'] ?? null) && $filters['status'] !== '') {
            $query->where('orders.status', $filters['status']);
        }

        if (is_string($filters['payment_status'] ?? null) && $filters['payment_status'] !== '') {
            $query->where('orders.payment_status', $filters['payment_status']);
        }

        if (is_string($filters['payment_method'] ?? null) && $filters['payment_method'] !== '') {
            $query->where('orders.payment_method', $filters['payment_method']);
        }
    }

    /**
     * The search term as a bound `LIKE` pattern, or null when absent.
     *
     * Wildcards in the term itself are escaped: a search for "50%" must look
     * for that string, not for anything beginning "50". The value is always
     * bound as a parameter, never interpolated.
     *
     * @param  array<string, mixed>  $filters
     */
    private function searchTerm(array $filters): ?string
    {
        $term = $filters['search'] ?? null;

        if (! is_string($term)) {
            return null;
        }

        $term = trim($term);

        if ($term === '') {
            return null;
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);

        return '%'.$escaped.'%';
    }

    /**
     * Count a report's rows, correctly for grouped queries.
     *
     * A grouped query's `->count()` counts rows *per group*, returning the size
     * of the first group rather than the number of groups. Wrapping it in a
     * subquery counts the groups, which is what a paginator needs.
     */
    private function countRows(BuilderContract $query): int
    {
        $base = $query instanceof QueryBuilder ? $query : $query->toBase();

        if ($base->groups !== null && $base->groups !== []) {
            $clone = clone $base;
            $clone->orders = null;
            $clone->limit = null;
            $clone->offset = null;

            return (int) DB::query()->fromSub($clone, 'grouped')->count();
        }

        $clone = clone $base;
        $clone->orders = null;

        return (int) $clone->count();
    }

    /**
     * Grand totals across the whole report, independent of the current page.
     *
     * A footer showing the page's totals rather than the report's is a number
     * that changes as you page through it — worse than showing nothing.
     *
     * Cached with the rows because it is a second aggregate over the same
     * window, and a table's footer is not worth a second uncached scan.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int|float>|null
     */
    private function totalsFor(ReportType $type, DateRange $range, array $filters): ?array
    {
        $derived = $this->derivedColumnKeys($type);

        /*
         * Derived columns are excluded, not merely skipped in the output.
         *
         * `average_order_value`, `effective_rate`, and `stock_state` are
         * computed in PHP from other columns and do not exist in the query's
         * result set — summing them would be a "column not found" error rather
         * than a wrong number. They are also the columns for which a column
         * total is meaningless: the sum of per-row averages is not the overall
         * average.
         */
        $numeric = array_values(array_filter(
            $type->columns(),
            static fn (array $column): bool => in_array($column['type'], ['money', 'number'], strict: true)
                && ! in_array($column['key'], $derived, strict: true),
        ));

        if ($numeric === []) {
            return null;
        }

        $key = sprintf('report:%s:totals:%s', $type->value, md5(serialize($filters)));

        $compute = function () use ($type, $range, $filters, $numeric): array {
            $inner = $this->query($type, $range, $filters);
            $base = $inner instanceof QueryBuilder ? $inner : $inner->toBase();

            $clone = clone $base;
            $clone->orders = null;

            /*
             * Identifiers are quoted through the connection's own grammar
             * rather than with literal backticks — those are MySQL syntax and
             * a syntax error on SQLite, which the test suite runs on. The
             * column names are enum-declared constants either way, never
             * request input.
             */
            $grammar = DB::connection()->getQueryGrammar();

            $selects = array_map(
                static fn (array $column): string => sprintf(
                    'COALESCE(SUM(%s), 0) as %s',
                    $grammar->wrap($column['key']),
                    $grammar->wrap($column['key']),
                ),
                $numeric,
            );

            /*
             * Summed over a subquery rather than by re-aggregating the report's
             * own query. Four of these reports already carry a GROUP BY, and
             * adding SUM() around an existing aggregate is either a syntax
             * error or — worse — silently sums the wrong grain.
             */
            $row = DB::query()
                ->fromSub($clone, 'report_rows')
                ->selectRaw(implode(', ', $selects))
                ->first();

            $totals = [];

            foreach ($numeric as $column) {
                $totals[$column['key']] = (int) ($row->{$column['key']} ?? 0);
            }

            return $totals;
        };

        return $type->isDateScoped()
            ? $this->cache->remember($key, $range, $compute)
            : $this->cache->rememberLive($key, $compute);
    }

    /**
     * Coerce a raw database row into the report's declared column shape.
     *
     * Two things happen here. Numeric columns are cast from the strings some
     * drivers return for aggregates, so a JSON response carries `1200` rather
     * than `"1200"`. And rows are reduced to exactly the declared columns, so a
     * column added to a query but not to {@see ReportType::columns()} cannot
     * leak into an export whose header row does not mention it.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normaliseRow(ReportType $type, array $row): array
    {
        $shaped = [];

        foreach ($type->columns() as $column) {
            $value = $row[$column['key']] ?? null;

            $shaped[$column['key']] = match ($column['type']) {
                'money', 'number' => (int) ($value ?? 0),
                'percent' => $value === null ? null : round((float) $value, 2),
                'date' => $value === null ? null : (string) $value,
                default => $value === null ? null : (string) $value,
            };
        }

        // Derived columns the SQL cannot express cheaply.
        return $this->deriveColumns($type, $shaped, $row);
    }

    /**
     * The columns {@see deriveColumns()} computes rather than selects.
     *
     * Kept immediately beside that method so the two cannot drift: a new
     * derived column added there without a matching entry here would be summed
     * by {@see totalsFor()} and fail with "column not found" at runtime.
     *
     * @return array<int, string>
     */
    private function derivedColumnKeys(ReportType $type): array
    {
        return match ($type) {
            ReportType::Customers => ['average_order_value'],
            ReportType::Tax => ['effective_rate'],
            ReportType::Inventory => ['stock_state'],
            default => [],
        };
    }

    /**
     * Columns computed from the row rather than selected.
     *
     * Division in particular is left to PHP: the guard against a zero
     * denominator is clearer here than as a `CASE` in every dialect, and these
     * are per-row arithmetic on an already-bounded result set rather than
     * something the database would do better.
     *
     * Anything added here needs a matching key in {@see derivedColumnKeys()}.
     *
     * @param  array<string, mixed>  $shaped
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function deriveColumns(ReportType $type, array $shaped, array $raw): array
    {
        if ($type === ReportType::Customers) {
            $orders = (int) ($shaped['orders'] ?? 0);
            $shaped['average_order_value'] = $orders > 0
                ? (int) round(((int) $shaped['lifetime_value']) / $orders)
                : 0;
        }

        if ($type === ReportType::Tax) {
            $base = (int) ($shaped['taxable_base'] ?? 0);
            $shaped['effective_rate'] = $base > 0
                ? round((((int) $shaped['tax_collected']) / $base) * 100, 2)
                : 0.0;
        }

        if ($type === ReportType::Inventory) {
            $stock = (int) ($shaped['stock'] ?? 0);
            $threshold = (int) ($shaped['threshold'] ?? 0);

            $shaped['stock_state'] = match (true) {
                $stock <= 0 => 'Out of stock',
                $stock <= $threshold => 'Low stock',
                default => 'In stock',
            };
        }

        if ($type === ReportType::Orders) {
            // `placed_at` is null until an order is accepted; the row should
            // still carry a date, and creation is the honest fallback.
            $shaped['placed_at'] ??= isset($raw['created_at']) ? (string) $raw['created_at'] : null;
        }

        return $shaped;
    }

    private function boundPerPage(int $perPage): int
    {
        $max = (int) config('reporting.limits.max_per_page', 100);

        return max(1, min($perPage, $max));
    }
}
