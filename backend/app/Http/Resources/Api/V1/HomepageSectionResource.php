<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\HomepageSection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A homepage section as the admin builder sees it: configuration, not content.
 *
 * The storefront receives a different shape entirely — sections with their
 * catalog content already resolved, assembled by HomepageService. Keeping the
 * two apart is deliberate: the builder needs `product_ids` to render a picker,
 * while the storefront needs the products themselves and must never be given
 * the ids to fetch separately.
 *
 * @mixin HomepageSection
 */
final class HomepageSectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'name' => $this->name,
            'heading' => $this->heading,
            'subheading' => $this->subheading,

            // Defaults merged in, so the builder's form always has every field
            // to bind to — including settings introduced after this row was
            // last saved.
            'settings' => $this->resolvedSettings(),

            'style' => [
                'background_color' => $this->background_color,
                'container_width' => $this->container_width ?? 'default',
            ],

            'is_enabled' => $this->is_enabled,
            'sort_order' => $this->sort_order,

            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),

            /*
             * Derived visibility, computed server-side.
             *
             * `is_enabled` alone would mislead the operator: a section can be
             * enabled and still invisible because its window has not opened.
             * The panel shows this rather than re-deriving the comparison and
             * risking a different answer from the one the storefront uses.
             */
            'window_state' => $this->windowState(),
            'is_live' => $this->is_enabled && $this->isWithinWindow(),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
