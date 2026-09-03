<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Validation\Rule;

/**
 * Update a shipping method. See UpdateShippingZoneRequest for why this extends
 * the store request rather than repeating its rules.
 */
final class UpdateShippingMethodRequest extends StoreShippingMethodRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['name'] = ['sometimes', 'string', 'max:128'];
        $rules['rate'] = ['sometimes', 'integer', 'min:0'];
        $rules['code'] = [
            'sometimes',
            'nullable',
            'string',
            'max:64',
            'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            Rule::unique('shipping_methods', 'code')->ignore($this->route('method')),
        ];

        return $rules;
    }
}
