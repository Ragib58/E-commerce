<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\Reporting\ReportCache;

/**
 * Drops cached dashboard and report figures when the data behind them moves.
 *
 * ## Why one listener for several events
 *
 * An order placed, an order's status changed, a payment settled, and a stock
 * adjustment all invalidate the same thing: every reporting aggregate. There is
 * no useful partial invalidation — a single new order changes total sales,
 * today's sales, the order count, four chart series, and the sales report's
 * totals row. Working out which subset to drop would be a calculation that is
 * wrong the moment a metric is added, and being wrong here shows up as a
 * dashboard that quietly disagrees with the order list beneath it.
 *
 * So the whole `reports` tag goes. See {@see ReportCache::flush()} for why
 * that trade is the right way round: recomputing a dashboard costs a second,
 * reconciling a wrong one costs an afternoon.
 *
 * ## Not queued
 *
 * Deliberately synchronous. A queued invalidation is a window — however
 * short — in which an admin who just marked an order delivered reloads the
 * dashboard and sees the old count, which reads as the action having failed.
 * Flushing a cache tag is a single Redis call; it does not need a worker.
 */
final class InvalidateReportingCache
{
    public function __construct(
        private readonly ReportCache $cache,
    ) {}

    /**
     * Every bound event routes here.
     *
     * The event itself is unused: what happened does not change what is
     * dropped, and typing the parameter to a union of five event classes would
     * add a line of maintenance for every future trigger without changing the
     * behaviour.
     */
    public function handle(object $event): void
    {
        $this->cache->flush();
    }
}
