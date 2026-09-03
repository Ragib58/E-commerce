<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a shipping method.
 */
final class StoreShippingMethodRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:128'],
            'code' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('shipping_methods', 'code')],
            'description' => ['nullable', 'string', 'max:512'],

            'rate' => ['required', 'integer', 'min:0'],
            'free_above' => ['nullable', 'integer', 'min:0'],

            'min_days' => ['nullable', 'integer', 'min:0', 'max:60'],
            'max_days' => ['nullable', 'integer', 'min:0', 'max:60', 'gte:min_days'],

            'countries' => ['nullable', 'array', 'max:250'],
            'countries.*' => ['string', 'size:2'],

            'min_subtotal' => ['nullable', 'integer', 'min:0'],
            'max_subtotal' => ['nullable', 'integer', 'min:0', 'gte:min_subtotal'],

            'is_active' => ['sometimes', 'boolean'],
            'requires_address' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'The code may contain only lowercase letters, numbers, and single hyphens.',
            'max_subtotal.gte' => 'The upper bound must be at or above the lower bound.',
            'max_days.gte' => 'The maximum estimate must be at or above the minimum.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if (isset($data['countries'])) {
            $data['countries'] = $data['countries'] === []
                ? null
                : array_map(strtoupper(...), $data['countries']);
        }

        return $data;
    }
}
