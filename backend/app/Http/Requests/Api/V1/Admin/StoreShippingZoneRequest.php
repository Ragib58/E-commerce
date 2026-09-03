<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a shipping zone.
 *
 * `authorize()` returns true because the route already carries
 * `permission:manage_shipping` — see routes/api/v1.php. Duplicating the check
 * here would be a second, weaker copy that can silently disagree with the
 * route middleware if either is ever changed alone.
 */
final class StoreShippingZoneRequest extends FormRequest
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
            'code' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('shipping_zones', 'code')],
            'description' => ['nullable', 'string', 'max:512'],

            /*
             * At least one criterion, or the fallback flag, is required —
             * a zone matching nothing is a configuration mistake the form
             * should catch rather than accept silently. See
             * ShippingZone::matches() for why an empty zone matches nothing at
             * request time too.
             */
            'countries' => ['nullable', 'array', 'max:50'],
            'countries.*' => ['string', 'size:2'],
            'states' => ['nullable', 'array', 'max:200'],
            'states.*' => ['string', 'max:128'],
            'cities' => ['nullable', 'array', 'max:200'],
            'cities.*' => ['string', 'max:128'],
            'postcodes' => ['nullable', 'array', 'max:500'],
            'postcodes.*' => ['string', 'max:32'],

            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_fallback' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'The code may contain only lowercase letters, numbers, and single hyphens.',
            'countries.*.size' => 'Use two-letter country codes, e.g. BD, US, GB.',
        ];
    }

    /**
     * Upper-cases country codes so matching is never a case mismatch, then
     * drops any list left empty by the client — an empty array should behave
     * exactly like the field being absent.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if (isset($data['countries'])) {
            $data['countries'] = array_map(strtoupper(...), $data['countries']);
        }

        foreach (['countries', 'states', 'cities', 'postcodes'] as $field) {
            if (isset($data[$field]) && $data[$field] === []) {
                $data[$field] = null;
            }
        }

        return $data;
    }
}
