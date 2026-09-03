<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Validation\Rule;

/**
 * Update a shipping zone.
 *
 * Extends the store request rather than duplicating its rules — the two must
 * accept the same shapes, and a field an admin can set on create but not on
 * edit is a surprise waiting to be reported as a bug. Only the uniqueness rule
 * differs, since the zone's own current code must not collide with itself.
 */
final class UpdateShippingZoneRequest extends StoreShippingZoneRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['name'] = ['sometimes', 'string', 'max:128'];
        $rules['code'] = [
            'sometimes',
            'nullable',
            'string',
            'max:64',
            'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            Rule::unique('shipping_zones', 'code')->ignore($this->route('zone')),
        ];

        return $rules;
    }
}
