<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Aggregate and per-dependency status reported by the health-check endpoint.
 */
enum HealthStatus: string
{
    /** Everything nominal. */
    case Ok = 'ok';

    /** Degraded: a non-critical dependency is failing but the app can serve traffic. */
    case Degraded = 'degraded';

    /** A critical dependency is unavailable; the app cannot serve traffic correctly. */
    case Down = 'down';

    /**
     * HTTP status an orchestrator (Docker healthcheck, k8s probe, load balancer)
     * should observe for this state.
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::Ok, self::Degraded => 200,
            self::Down => 503,
        };
    }

    public function isHealthy(): bool
    {
        return $this === self::Ok;
    }

    /**
     * Reduce per-dependency statuses to a single aggregate.
     *
     * A failing critical dependency drives the whole check to `down`; a failing
     * optional one only degrades it, so a flaky cache never takes the service
     * out of the load-balancer pool.
     *
     * @param  array<int, self>  $statuses
     */
    public static function aggregate(array $statuses): self
    {
        if ($statuses === []) {
            return self::Ok;
        }

        if (in_array(self::Down, $statuses, strict: true)) {
            return self::Down;
        }

        if (in_array(self::Degraded, $statuses, strict: true)) {
            return self::Degraded;
        }

        return self::Ok;
    }
}
