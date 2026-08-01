<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Permission
 */
final class PermissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = [
            'name' => $this->name,
            'label' => $this->label,
            'group' => $this->group,
            'description' => $this->description,
        ];

        // Present only when this permission was loaded through an admin's
        // direct-override pivot, where false means an explicit revoke rather
        // than a mere absence.
        //
        // Both guards are load-bearing under Model::shouldBeStrict():
        //   - relationLoaded('pivot') — a permission fetched directly (via
        //     /admin/permissions) has no pivot at all, and reading `$this->pivot`
        //     would throw MissingAttributeException rather than return null.
        //   - array key check — a permission reached through `permission_role`
        //     DOES have a pivot, but that table has no `is_granted` column, so
        //     getAttribute() on it would throw too.
        $pivot = $this->resource->relationLoaded('pivot') ? $this->resource->getRelation('pivot') : null;

        if ($pivot !== null && array_key_exists('is_granted', $pivot->getAttributes())) {
            $payload['is_granted'] = (bool) $pivot->getAttribute('is_granted');
        }

        return $payload;
    }
}
