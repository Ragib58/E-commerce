<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised whenever admin-managed settings change.
 *
 * Keeps SettingsService free of HTTP and cache-topology concerns: the service
 * announces "settings changed" and listeners decide what that implies
 * (bumping the version stamp, revalidating the storefront's ISR cache).
 */
final class SettingsUpdated
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<int, string>  $changedKeys  Empty means "an unspecified subset changed".
     */
    public function __construct(
        public readonly array $changedKeys = [],
    ) {
    }

    /**
     * Whether any changed key belongs to a group the storefront renders.
     */
    public function affectsStorefront(): bool
    {
        if ($this->changedKeys === []) {
            return true;
        }

        foreach ($this->changedKeys as $key) {
            $prefix = strtok($key, '.');

            if (in_array($prefix, ['general', 'branding', 'theme', 'contact', 'social', 'seo', 'feature'], strict: true)) {
                return true;
            }
        }

        return false;
    }
}
