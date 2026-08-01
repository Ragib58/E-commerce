<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\HealthCheckService;
use App\Services\SettingsService;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the domain service layer into the container.
 *
 * Services are singletons because they are stateless and cache-backed —
 * resolving SettingsService twice in one request would otherwise duplicate
 * cache round-trips.
 *
 * Deferred: none of these are needed to boot the framework, so they are only
 * instantiated when a controller actually type-hints one.
 */
final class DomainServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(HealthCheckService::class);
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            SettingsService::class,
            HealthCheckService::class,
        ];
    }
}
