<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\CouponType;
use Illuminate\Validation\Rule;

/**
 * Update a coupon. Extends the store request for the same reason
 * UpdateShippingZoneRequest does — see that class.
 */
final class UpdateCouponRequest extends StoreCouponRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['code'] = [
            'sometimes',
            'string',
            'min:3',
            'max:64',
            'regex:/^[A-Za-z0-9_-]+$/',
            Rule::unique('coupons', 'code')->ignore($this->route('coupon')),
        ];
        $rules['name'] = ['sometimes', 'string', 'max:128'];
        $rules['type'] = ['sometimes', Rule::enum(CouponType::class)];
        $rules['value'] = ['sometimes', 'numeric', 'min:0.01'];

        return $rules;
    }
}
