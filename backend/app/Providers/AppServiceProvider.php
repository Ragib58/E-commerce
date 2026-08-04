<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\CatalogChanged;
use App\Events\ContentChanged;
use App\Events\SettingsUpdated;
use App\Events\StockAdjusted;
use App\Listeners\InvalidateCatalogCache;
use App\Listeners\InvalidateContentCache;
use App\Listeners\InvalidateFrontendCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureUrls();
        $this->configurePasswordRules();
        $this->registerEventListeners();
    }

    /**
     * Strict model behaviour, enabled outside production.
     *
     * `preventLazyLoading` turns an accidental N+1 into a test failure rather
     * than a silent performance regression discovered in production. It is
     * disabled in production so a missed eager-load degrades performance
     * instead of throwing a 500 at a customer.
     */
    private function configureModels(): void
    {
        Model::shouldBeStrict(! app()->isProduction());
        Model::unguard(false);
    }

    /**
     * Behind Nginx and a TLS-terminating proxy, Laravel sees plain HTTP and
     * would generate http:// URLs in mail and redirects. Force HTTPS when the
     * app URL declares it.
     */
    private function configureUrls(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }

    private function configurePasswordRules(): void
    {
        Password::defaults(function (): Password {
            $rule = Password::min(12)->letters()->numbers()->symbols();

            return app()->isProduction()
                ? $rule->uncompromised()
                : $rule;
        });
    }

    private function registerEventListeners(): void
    {
        Event::listen(SettingsUpdated::class, InvalidateFrontendCache::class);

        /*
         * Catalog and stock changes purge the storefront's cached product
         * pages. Bound to explicit methods because one listener handles both
         * events with different rules about when a purge is warranted.
         */
        Event::listen(CatalogChanged::class, [InvalidateCatalogCache::class, 'handleCatalogChanged']);
        Event::listen(StockAdjusted::class, [InvalidateCatalogCache::class, 'handleStockAdjusted']);

        /*
         * Homepage, banner, and CMS page edits purge the cached storefront
         * content payload. Separate from the catalog listener because the tags
         * differ: a product edit must not discard the homepage layout, and a
         * banner swap must not discard every cached product page.
         */
        Event::listen(ContentChanged::class, [InvalidateContentCache::class, 'handle']);

        /*
         * A catalog change invalidates the homepage too: its cached payload
         * embeds resolved product cards, so a price change or an unpublish
         * would otherwise stay advertised there under a tag the catalog
         * listener does not touch.
         */
        Event::listen(CatalogChanged::class, [InvalidateContentCache::class, 'handleCatalogChanged']);
    }
}
