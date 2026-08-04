<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised when admin-managed storefront content changes.
 *
 * Mirrors CatalogChanged and SettingsUpdated: the service announces *what*
 * changed, and a listener decides what that implies for the caches. Services
 * therefore never import cache topology, and the invalidation rule for content
 * lives in exactly one place.
 *
 * Unlike catalog changes, a content change is almost always storefront-visible:
 * an operator editing the homepage is editing the rendered page itself. The one
 * exception worth modelling is a draft — hence `affectsStorefront()`.
 */
final class ContentChanged
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  string  $entity  'homepage', 'banner', or 'page'.
     * @param  string|null  $identifier  Slug or id of the affected record, when one is known.
     * @param  bool  $isPublic  Whether the record is visible to shoppers after the change.
     * @param  bool  $wasPublic  Whether it was visible before. A draft edited into
     *                           another draft changes no rendered page.
     */
    public function __construct(
        public readonly string $entity,
        public readonly ?string $identifier = null,
        public readonly bool $isPublic = true,
        public readonly bool $wasPublic = false,
    ) {
    }

    public function affectsStorefront(): bool
    {
        return $this->isPublic || $this->wasPublic;
    }
}
