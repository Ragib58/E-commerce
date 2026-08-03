<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised when catalog content the storefront renders is created, changed, or
 * removed.
 *
 * Mirrors SettingsUpdated: the service announces the change, and a listener
 * decides what it implies for the Next.js cache. Services stay free of cache
 * topology, and the invalidation rule lives in exactly one place.
 */
final class CatalogChanged
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  string  $entity  'product', 'category', or 'brand'.
     * @param  string|null  $slug  The affected record, when a single one is known.
     * @param  bool  $wasPublic  Whether the record was visible to shoppers before
     *                           the change. A draft edited into another draft
     *                           changes nothing the storefront has cached.
     */
    public function __construct(
        public readonly string $entity,
        public readonly ?string $slug = null,
        public readonly bool $isPublic = true,
        public readonly bool $wasPublic = false,
    ) {
    }

    /**
     * Whether the storefront's cache actually needs purging.
     *
     * Editing a draft affects no rendered page, so revalidating for it would
     * discard a warm cache for nothing. A record that was public and is now
     * hidden still counts — its page must stop being served.
     */
    public function affectsStorefront(): bool
    {
        return $this->isPublic || $this->wasPublic;
    }
}
