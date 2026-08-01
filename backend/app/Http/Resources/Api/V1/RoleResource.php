<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Role
 */
final class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'description' => $this->description,
            'level' => $this->level,
            'is_system' => $this->is_system,

            // Only serialised when eager-loaded, so rendering a role inside an
            // admin list cannot trigger an N+1 across the pivot.
            'permissions' => PermissionResource::collection($this->whenLoaded('permissions')),
            'permissions_count' => $this->whenCounted('permissions'),
        ];
    }
}
