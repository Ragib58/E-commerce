<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ReportType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ReportQueryRequest;
use App\Services\Reporting\ExportService;
use App\Services\Reporting\ReportService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The seven tabular reports, on screen and as downloads.
 *
 * One endpoint per verb rather than one per report: the reports differ in their
 * columns and their query, both of which are declared data
 * ({@see ReportType::columns()}, {@see ReportService::query()}) rather than
 * code that needs its own route. Adding an eighth report is an enum case and a
 * query method, with no controller change at all.
 */
final class ReportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ReportService $reports,
        private readonly ExportService $exports,
    ) {}

    /**
     * GET /admin/reports
     *
     * The catalogue of available reports, with their columns and which
     * controls each supports.
     */
    public function index(): JsonResponse
    {
        return $this->successResponse(
            data: ['reports' => ReportType::options()],
            message: 'Reports retrieved successfully.',
        );
    }

    /**
     * GET /admin/reports/{report}
     *
     * A page of one report, with its column definitions and grand totals.
     */
    public function show(ReportQueryRequest $request, string $report): JsonResponse
    {
        $type = $this->resolveType($report);

        return $this->successResponse(
            data: $this->reports->paginate(
                type: $type,
                range: $request->dateRange(),
                filters: $request->filters($type->value),
                perPage: $request->perPage(),
                page: $request->pageNumber(),
            ),
            message: 'Report generated successfully.',
        );
    }

    /**
     * GET /admin/reports/{report}/export
     *
     * The same report as a file.
     *
     * Returns a framework response rather than the API envelope: the body is
     * the file. Failures still use the envelope — an over-large export or an
     * unavailable format throws a ValidationException before any bytes are
     * written, which the API exception handler renders as a normal 422.
     */
    public function export(ReportQueryRequest $request, string $report): SymfonyResponse
    {
        $type = $this->resolveType($report);

        return $this->exports->export(
            type: $type,
            format: $request->exportFormat(),
            range: $request->dateRange(),
            filters: $request->filters($type->value),
        );
    }

    /**
     * Resolve the route segment to a report type.
     *
     * A 404 rather than a 422: the report name is part of the path, so an
     * unknown one is an address that does not exist, not a bad field.
     */
    private function resolveType(string $report): ReportType
    {
        return ReportType::tryFrom($report)
            ?? abort(404, 'That report does not exist.');
    }
}
