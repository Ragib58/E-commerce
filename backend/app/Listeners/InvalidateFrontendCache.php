<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SettingsUpdated;
use App\Jobs\RevalidateFrontendCache;
use App\Services\SettingsService;

/**
 * Bridges a settings change to the storefront's cache.
 *
 * Runs synchronously (bumping an integer is cheap and must happen before the
 * response returns), then queues the outbound HTTP revalidation call so a slow
 * or unreachable frontend never blocks an admin's save request.
 */
final class InvalidateFrontendCache
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }

    public function handle(SettingsUpdated $event): void
    {
        $this->settings->bumpVersion();

        if (! $event->affectsStorefront()) {
            return;
        }

        if (! config('api.revalidation.enabled')) {
            return;
        }

        RevalidateFrontendCache::dispatch(['settings'], $event->changedKeys);
    }
}
