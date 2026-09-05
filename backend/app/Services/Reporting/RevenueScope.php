<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\Order;
use App\Support\DateRange;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which orders count as revenue, and over what window.
 *
 * ## The distinction this class exists to keep
 *
 * There are two different questions a dashboard asks about orders, and
 * answering both with the same query is the most common way a sales figure
 * ends up wrong:
 *
 *  - **"How much did we take?"** counts only orders whose payment settled, and
 *    subtracts what was refunded. A cancelled order and a failed payment are
 *    both rows in `orders`; neither is money.
 *  - **"How many orders came in?"** counts every order placed, cancellations
 *    included, because that is what an operations view is asking.
 *
 * {@see revenue()} answers the first and {@see orders()} the second. Every
 * metric, chart, and report in this phase routes through one of them, so the
 * definition of a sale cannot drift between the dashboard tile and the report
 * that is supposed to reconcile with it.
 *
 * ## Net, not gross
 *
 * `grand_total` is what was charged; `refunded_total` is what went back. Sales
 * figures use the difference, which is why a partially refunded order stays in
 * the population rather than being excluded — the unrefunded remainder is
 * genuine revenue, and dropping the whole order would understate it.
 */
final class RevenueScope
{
    /**
     * Orders that count toward revenue, within the range.
     *
     * @return Builder<Order>
     */
    public function revenue(DateRange $range, ?Builder $query = null): Builder
    {
        return $this->applyRevenueFilter($this->orders($range, $query));
    }

    /**
     * Every order placed within the range, whatever became of it.
     *
     * @return Builder<Order>
     */
    public function orders(DateRange $range, ?Builder $query = null): Builder
    {
        return ($query ?? Order::query())
            ->whereBetween('orders.created_at', [$range->from, $range->to]);
    }

    /**
     * Narrow an existing order query to those that count as revenue, without
     * imposing a date range.
     *
     * Used by all-time totals, where there is no window to apply.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function applyRevenueFilter(Builder $query): Builder
    {
        return $query
            ->whereIn('orders.payment_status', $this->paidStatuses())
            ->whereNotIn('orders.status', $this->excludedStatuses());
    }

    /**
     * The SQL expression for net revenue on an order row.
     *
     * Returned as a fragment rather than computed in PHP because the figure is
     * almost always wanted as a `SUM` over thousands of rows — pulling those
     * rows into memory to add them up is the query pattern this whole phase
     * exists to avoid.
     *
     * Contains no user input: both column names are literals.
     */
    public function netRevenueExpression(): string
    {
        return '(orders.grand_total - orders.refunded_total)';
    }

    /**
     * Payment statuses that count toward sales.
     *
     * @return array<int, string>
     */
    public function paidStatuses(): array
    {
        /** @var array<int, string> $statuses */
        $statuses = (array) config('reporting.revenue.paid_statuses', ['paid', 'partially_refunded']);

        return $statuses;
    }

    /**
     * Order statuses excluded from revenue whatever their payment status.
     *
     * @return array<int, string>
     */
    public function excludedStatuses(): array
    {
        /** @var array<int, string> $statuses */
        $statuses = (array) config('reporting.revenue.excluded_statuses', ['cancelled', 'refunded']);

        return $statuses;
    }
}
