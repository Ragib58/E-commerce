<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Checkout;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Checkout step 2 — where the goods go.
 *
 * Rules live in {@see AddressRules} so the shipping and billing steps cannot
 * drift apart. See that trait for why the schema is deliberately permissive
 * about postcodes and states.
 */
final class ShippingAddressRequest extends FormRequest
{
    use AddressRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->addressRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->addressMessages();
    }
}
