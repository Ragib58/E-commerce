<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ExportFormat;
use App\Enums\ReportPeriod;
use App\Enums\ReportType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ReportQueryRequest;
use App\Services\Reporting\ChartService;
use App\Services\Reporting\DashboardService;
use App\Services\Reporting\ExportService;
use App\Services\Reporting\ReportCache;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The admin dashboard: headline metrics and chart series.
 *
 * Every figure returned here is a cached aggregate — see
 * {@see ReportCache} for why the whole tag is dropped
 * on any order, payment, or stock movement rather than invalidated
 * selectively.
 *
 * Money is returned as integer minor units throughout, matching the rest of
 * this API. Formatting is the client's job; a service that returned a
 * pre-formatted string would bake the store's current currency symbol into a
 * value cached for an hour.
 */
final class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly ChartService $charts,
        private readonly ExportService $exports,
    ) {}

    /**
     * GET /admin/dashboard
     *
     * Metrics and charts in one response.
     *
     * Deliberately a single endpoint rather than one per tile: a dashboard
     * renders all of this at once, and ten requests would each pay the HTTP,
     * auth, and permission cost to return one number.
     */
    public function index(ReportQueryRequest $request): JsonResponse
    {
        $range = $request->dateRange();

        return $this->successResponse(
            data: [
                'metrics' => $this->dashboard->metrics($range),
                'charts' => $this->charts->all($range, $request->topN()),
                'low_stock' => $this->dashboard->lowStockProducts(),
            ],
            message: 'Dashboard loaded successfully.',
        );
    }

    /**
     * GET /admin/dashboard/metrics
     *
     * The metric tiles alone, for a client that polls them without redrawing
     * every chart.
     */
    public function metrics(ReportQueryRequest $request): JsonResponse
    {
        return $this->successResponse(
            data: $this->dashboard->metrics($request->dateRange()),
            message: 'Metrics retrieved successfully.',
        );
    }

    /**
     * GET /admin/dashboard/charts
     */
    public function charts(ReportQueryRequest $request): JsonResponse
    {
        return $this->successResponse(
            data: $this->charts->all($request->dateRange(), $request->topN()),
            message: 'Charts retrieved successfully.',
        );
    }

    /**
     * GET /admin/dashboard/filters
     *
     * The vocabulary the panel's filter controls are built from — periods,
     * report types with their columns, and the export formats this
     * installation can actually produce.
     *
     * Served from the API rather than duplicated in the frontend so a report
     * gaining a column, or a server losing its PDF library, does not require a
     * frontend release to stay accurate.
     */
    public function filters(): JsonResponse
    {
        return $this->successResponse(
            data: [
                'periods' => ReportPeriod::options(),
                'reports' => ReportType::options(),
                'formats' => $this->exports->availableFormats(),
                'all_formats' => ExportFormat::options(),
            ],
            message: 'Filter options retrieved successfully.',
        );
    }
}
