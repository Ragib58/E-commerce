<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Set what a shipping method costs within a zone.
 *
 * `shipping_zone_id` is taken from the request rather than the route, because
 * a rate is created from the method's own admin screen — "here is what Express
 * costs in each zone" — and the method is what the URL already names.
 */
final class StoreShippingRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'shipping_zone_id' => ['required', 'integer', 'exists:shipping_zones,id'],

            // Minor units, matching every other money field in the schema.
            'rate' => ['required', 'integer', 'min:0'],
            'free_above' => ['nullable', 'integer', 'min:0'],

            'min_subtotal' => ['nullable', 'integer', 'min:0'],
            'max_subtotal' => ['nullable', 'integer', 'min:0', 'gte:min_subtotal'],

            'min_days' => ['nullable', 'integer', 'min:0', 'max:60'],
            'max_days' => ['nullable', 'integer', 'min:0', 'max:60', 'gte:min_days'],

            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shipping_zone_id.exists' => 'That shipping zone could not be found.',
            'max_subtotal.gte' => 'The upper bound must be at or above the lower bound.',
            'max_days.gte' => 'The maximum estimate must be at or above the minimum.',
        ];
    }
}
