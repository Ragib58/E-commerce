<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\BrandService;
use App\Services\CatalogService;
use App\Services\CategoryService;
use App\Services\HealthCheckService;
use App\Services\InventoryService;
use App\Services\MediaService;
use App\Services\ProductService;
use App\Services\SettingsService;
use App\Services\VariantService;
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

        /*
         * Catalog and inventory services.
         *
         * Singletons for the same reason as the others: they hold no
         * per-request state, and InventoryService in particular is resolved by
         * both ProductService and VariantService within a single request.
         */
        $this->app->singleton(MediaService::class);
        $this->app->singleton(InventoryService::class);
        $this->app->singleton(CategoryService::class);
        $this->app->singleton(BrandService::class);
        $this->app->singleton(ProductService::class);
        $this->app->singleton(VariantService::class);
        $this->app->singleton(CatalogService::class);
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            SettingsService::class,
            HealthCheckService::class,
            MediaService::class,
            InventoryService::class,
            CategoryService::class,
            BrandService::class,
            ProductService::class,
            VariantService::class,
            CatalogService::class,
        ];
    }
}
