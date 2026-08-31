<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Checkout;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Checkout step 5 — how the customer intends to pay.
 *
 * The enum bounds the field to methods that exist. Whether a method is
 * *enabled* for this store, and whether it needs a gateway that is not wired up
 * yet, are both decided in CheckoutService — they depend on settings that can
 * change between the request being validated and the choice being stored.
 *
 * There is no card number, CVV, or expiry field here, and there must not be.
 * Card data goes to the gateway directly from the client; an application that
 * accepts it into its own request cycle has put itself in PCI scope and made
 * its logs a breach.
 */
final class PaymentMethodRequest extends FormRequest
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
            'payment_method' => ['required', 'string', Rule::in(PaymentMethod::values())],

            /*
             * The shopper's note travels with the order and prints on the
             * packing slip. Bounded because it is free text heading for a
             * document someone has to read.
             */
            'customer_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_method.required' => 'Choose how you would like to pay.',
            'payment_method.in' => 'That payment method is not available.',
        ];
    }
}
