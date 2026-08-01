<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\HealthStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Probes every backing service and reports an aggregate status.
 *
 * Each dependency is probed with a real round-trip (a query, a SET/GET, a
 * write) rather than a connection-object check — a live TCP connection to a
 * database whose disk is full still reports "connected".
 *
 * Dependencies are classified critical or optional. A failing critical
 * dependency returns 503 so an orchestrator pulls the instance out of the
 * pool; a failing optional one returns 200 with `degraded`, because taking the
 * whole service down over a flaky object store would be worse than serving.
 */
final class HealthCheckService
{
    /** @var array<string, bool> Dependency name => is critical. */
    private const DEPENDENCIES = [
        'database' => true,
        'cache' => true,
        'redis' => true,
        'queue' => false,
        'storage' => false,
    ];

    /**
     * Liveness: is the PHP process itself up? Deliberately probes nothing.
     *
     * Used by the container's HEALTHCHECK. A liveness probe that checks
     * dependencies causes cascading restarts — the app gets killed because
     * the database is slow, which does not fix the database.
     *
     * @return array<string, mixed>
     */
    public function liveness(): array
    {
        return [
            'status' => HealthStatus::Ok->value,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Readiness: can this instance actually serve traffic?
     *
     * @return array{status: HealthStatus, checks: array<string, mixed>, meta: array<string, mixed>}
     */
    public function readiness(): array
    {
        $startedAt = microtime(true);
        $checks = [];
        $statuses = [];

        foreach (array_keys(self::DEPENDENCIES) as $dependency) {
            $result = $this->probe($dependency);

            $checks[$dependency] = $result;
            $statuses[] = HealthStatus::from($result['status']);
        }

        return [
            'status' => HealthStatus::aggregate($statuses),
            'checks' => $checks,
            'meta' => [
                'application' => config('app.name'),
                'environment' => config('app.env'),
                'version' => (string) config('api.default_version'),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'timestamp' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array{status: string, critical: bool, latency_ms: float|null, message: string|null, details?: array<string, mixed>}
     */
    private function probe(string $dependency): array
    {
        $critical = self::DEPENDENCIES[$dependency];
        $startedAt = microtime(true);

        try {
            $details = match ($dependency) {
                'database' => $this->checkDatabase(),
                'cache' => $this->checkCache(),
                'redis' => $this->checkRedis(),
                'queue' => $this->checkQueue(),
                'storage' => $this->checkStorage(),
                default => throw new \InvalidArgumentException("Unknown dependency [{$dependency}]."),
            };

            return [
                'status' => HealthStatus::Ok->value,
                'critical' => $critical,
                'latency_ms' => $this->elapsed($startedAt),
                'message' => null,
                'details' => $details,
            ];
        } catch (Throwable $e) {
            Log::warning('Health check probe failed.', [
                'dependency' => $dependency,
                'critical' => $critical,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [
                'status' => ($critical ? HealthStatus::Down : HealthStatus::Degraded)->value,
                'critical' => $critical,
                'latency_ms' => $this->elapsed($startedAt),
                // Never surface raw exception text in production — connection
                // strings and credentials routinely appear in driver messages.
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Dependency probe failed.',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDatabase(): array
    {
        $connection = DB::connection();
        $connection->select('SELECT 1');

        return [
            'driver' => $connection->getDriverName(),
            'database' => $connection->getDatabaseName(),
        ];
    }

    /**
     * Round-trips a real value: a cache that accepts writes but returns
     * nothing on read is broken in a way a ping would not reveal.
     *
     * @return array<string, mixed>
     */
    private function checkCache(): array
    {
        $key = 'health:probe:' . Str::random(12);
        $expected = (string) now()->getTimestampMs();

        Cache::put($key, $expected, 10);
        $actual = Cache::get($key);
        Cache::forget($key);

        if ($actual !== $expected) {
            throw new \RuntimeException('Cache round-trip returned an unexpected value.');
        }

        return ['store' => config('cache.default')];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkRedis(): array
    {
        $connection = Redis::connection(config('cache.stores.redis.connection', 'cache'));
        $connection->ping();

        return ['connection' => (string) config('cache.stores.redis.connection', 'cache')];
    }

    /**
     * Confirms the queue backend is reachable and reports depth, so a probe
     * also surfaces a worker that has stopped draining the queue.
     *
     * @return array<string, mixed>
     */
    private function checkQueue(): array
    {
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            return ['connection' => $connection, 'pending' => 0];
        }

        $pending = app('queue')->connection($connection)->size(
            (string) config("queue.connections.{$connection}.queue", 'default')
        );

        return [
            'connection' => $connection,
            'pending' => $pending,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkStorage(): array
    {
        $disk = Storage::disk(config('filesystems.default'));
        $path = 'health/' . Str::random(12) . '.tmp';

        $disk->put($path, 'ok');
        $contents = $disk->get($path);
        $disk->delete($path);

        if ($contents !== 'ok') {
            throw new \RuntimeException('Storage round-trip returned an unexpected value.');
        }

        return ['disk' => (string) config('filesystems.default')];
    }

    private function elapsed(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 2);
    }
}
