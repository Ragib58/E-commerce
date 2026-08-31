<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Checkout;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Checkout step 4 — the delivery method.
 *
 * Note what is absent: `rate`, `cost`, `shipping_total`. The client names a
 * method; the price comes from that method's own row, resolved in
 * CheckoutService. There is no field here to submit a shipping cost into, which
 * is the same guarantee the cart makes about product prices — the class of bug
 * is absent rather than validated against.
 *
 * Whether the method is *available* for this order's subtotal and destination
 * is checked in the service, not here: it depends on catalog and session state
 * at the moment of the write, which a validation rule reading the request
 * cannot see.
 */
final class ShippingMethodRequest extends FormRequest
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
            // The method's uuid. Integer ids never leave the server — see
            // ShippingMethod::getRouteKeyName.
            'shipping_method' => ['required', 'string', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shipping_method.required' => 'Choose a delivery method.',
        ];
    }
}
