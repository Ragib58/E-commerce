<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialises a single setting for admin-facing consumers.
 *
 * The public storefront endpoint does not use this — it returns a flat
 * key/value map (see SettingsController::public) because the frontend needs
 * values, not metadata.
 *
 * @mixin Setting
 */
final class SettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'value' => $this->typedValue(),
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'group' => $this->group->value,
            'group_label' => $this->group->label(),
            'label' => $this->label,
            'description' => $this->description,
            'is_public' => $this->is_public,
            'is_locked' => $this->is_locked,
            'sort_order' => $this->sort_order,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
