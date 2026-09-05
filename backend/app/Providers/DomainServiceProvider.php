<?php

declare(strict_types=1);

namespace App\Providers;

use App\Payments\PaymentGatewayManager;
use App\Services\BannerService;
use App\Services\BrandService;
use App\Services\CartService;
use App\Services\CatalogService;
use App\Services\CategoryService;
use App\Services\CmsPageService;
use App\Services\CouponService;
use App\Services\HealthCheckService;
use App\Services\HomepageService;
use App\Services\HtmlSanitiser;
use App\Services\InventoryService;
use App\Services\MediaService;
use App\Services\NotificationPreferenceService;
use App\Services\PaymentService;
use App\Services\ProductService;
use App\Services\Reporting\ChartService;
use App\Services\Reporting\DashboardService;
use App\Services\Reporting\ExportService;
use App\Services\Reporting\ReportService;
use App\Services\Reporting\RevenueScope;
use App\Services\SettingsService;
use App\Services\ShippingZoneService;
use App\Services\VariantService;
use App\Services\WishlistService;
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

        /*
         * Storefront content — the homepage builder, banners, CMS pages.
         *
         * HtmlSanitiser is a singleton despite holding no state: it reads its
         * allowlist from config on every call, and resolving it fresh for each
         * of a page's sections would repeat that lookup for nothing.
         */
        $this->app->singleton(HtmlSanitiser::class);
        $this->app->singleton(BannerService::class);
        $this->app->singleton(CmsPageService::class);
        $this->app->singleton(HomepageService::class);

        /*
         * Storefront shopping.
         *
         * CartService is the sole authority on cart pricing — it recomputes
         * every figure from the catalog and accepts none from a request.
         */
        $this->app->singleton(CartService::class);
        $this->app->singleton(WishlistService::class);

        /*
         * The payment layer.
         *
         * PaymentGatewayManager is a singleton because it memoises the gateway
         * instances it builds. BkashGateway caches a grant token, and resolving
         * a fresh manager per injection point would rebuild those and repeat
         * the lookup within a single request.
         *
         * Gateways themselves are deliberately NOT registered here. They are
         * resolved through the manager from `config/payment.gateways`, which is
         * what keeps the registry as data — a container binding per gateway
         * would have to be re-registered whenever that data changed, and would
         * put every gateway's class name back into the core.
         */
        $this->app->singleton(PaymentGatewayManager::class);
        $this->app->singleton(PaymentService::class);

        /*
         * Shipping zones, coupons, and notification preferences.
         *
         * All three are stateless and read-heavy in the same way the services
         * above are — a checkout page resolves a zone, prices several methods
         * against it, and may validate a coupon in the same request, so
         * sharing one instance avoids re-reading config and settings per call.
         */
        $this->app->singleton(ShippingZoneService::class);
        $this->app->singleton(CouponService::class);
        $this->app->singleton(NotificationPreferenceService::class);

        /*
         * Reporting.
         *
         * Singletons for the same reason as the services above: one dashboard
         * request runs a dozen aggregates through them, and DashboardService
         * in particular memoises per-request figures on the instance, which a
         * fresh object per injection point would discard.
         *
         * ReportCache is deliberately absent — it is bound in
         * AppServiceProvider instead, because the event listener that flushes
         * it resolves during boot and would force this deferred provider to
         * load on every request. See the note there.
         */
        $this->app->singleton(RevenueScope::class);
        $this->app->singleton(DashboardService::class);
        $this->app->singleton(ChartService::class);
        $this->app->singleton(ReportService::class);
        $this->app->singleton(ExportService::class);
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
            HtmlSanitiser::class,
            BannerService::class,
            CmsPageService::class,
            HomepageService::class,
            CartService::class,
            WishlistService::class,
            PaymentGatewayManager::class,
            PaymentService::class,
            ShippingZoneService::class,
            CouponService::class,
            NotificationPreferenceService::class,
            RevenueScope::class,
            DashboardService::class,
            ChartService::class,
            ReportService::class,
            ExportService::class,
        ];
    }
}
