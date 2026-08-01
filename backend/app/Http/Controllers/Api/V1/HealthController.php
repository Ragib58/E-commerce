<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\HealthStatus;
use App\Http\Controllers\Controller;
use App\Services\HealthCheckService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Health and readiness endpoints.
 *
 * Two separate probes on purpose:
 *   GET /api/v1/health       — liveness. Is the PHP process responding?
 *   GET /api/v1/health/ready — readiness. Are all dependencies usable?
 *
 * Container HEALTHCHECK and load balancers should poll liveness; deployment
 * gates and orchestrator readiness probes should poll readiness. Pointing a
 * liveness probe at dependency checks causes restart storms when a database
 * hiccups — restarting the app does not fix the database.
 */
final class HealthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly HealthCheckService $health,
    ) {
    }

    /**
     * Liveness probe. Always cheap, never touches a dependency.
     */
    public function index(): JsonResponse
    {
        return $this->successResponse(
            data: $this->health->liveness(),
            message: 'Service is alive.',
        );
    }

    /**
     * Readiness probe. Returns 503 when a critical dependency is unavailable
     * so orchestrators stop routing traffic to this instance.
     */
    public function ready(): JsonResponse
    {
        $report = $this->health->readiness();

        /** @var HealthStatus $status */
        $status = $report['status'];

        return $this->successResponse(
            data: [
                'status' => $status->value,
                'checks' => $report['checks'],
            ],
            message: match ($status) {
                HealthStatus::Ok => 'All systems operational.',
                HealthStatus::Degraded => 'Operational with degraded dependencies.',
                HealthStatus::Down => 'One or more critical dependencies are unavailable.',
            },
            status: $status->httpStatus(),
            meta: $report['meta'],
        );
    }
}
