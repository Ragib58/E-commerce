<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\SettingsUpdated;
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
    }
}
