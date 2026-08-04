<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A banner, for either the storefront or the admin panel.
 *
 * The scheduling window and the raw status are admin-only — not because they
 * are secret, but because a storefront that received them would be tempted to
 * filter on them client-side, and visibility is decided in SQL. Anything the
 * public surface receives is, by construction, already live.
 *
 * @mixin Banner
 */
final class BannerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Both guards, matching ProductResource: the API panel authenticates
        // with `admin-api`, the Blade panel with the session `admin` guard, and
        // an admin is an admin on either.
        $isAdmin = $request->user('admin-api') !== null || $request->user('admin') !== null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,

            'image' => $this->image_url,
            // Falls back to the desktop image in the model, so the frontend can
            // always set a <source> without a null check.
            'mobile_image' => $this->mobile_image_url,
            'alt_text' => $this->resolved_alt_text,

            'link_url' => $this->link_url,
            'link_label' => $this->link_label,
            'link_external' => $this->link_external,

            'placement' => $this->placement->value,
            'sort_order' => $this->sort_order,

            $this->mergeWhen($isAdmin, [
                'status' => $this->status->value,
                'starts_at' => $this->starts_at?->toIso8601String(),
                'ends_at' => $this->ends_at?->toIso8601String(),

                // Derived, so the panel's status chip does not have to
                // re-implement the window comparison the backend already owns.
                'window_state' => $this->windowState(),
                'is_live' => $this->status->isPublishable() && $this->isWithinWindow(),

                'created_at' => $this->created_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),
            ]),
        ];
    }
}
