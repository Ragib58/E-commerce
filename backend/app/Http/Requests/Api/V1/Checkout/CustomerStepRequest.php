<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Checkout;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Checkout step 1 — customer details.
 *
 * Open to guests: guest checkout is a first-class path here, not a degraded
 * one. A signed-in customer's details are pre-filled by CheckoutService but
 * remain editable, because an order going to a different recipient is ordinary.
 *
 * The email is the load-bearing field for a guest. It is where the confirmation
 * goes, and together with the order number it is the credential for looking the
 * order up later — so it is validated as a real address rather than merely as a
 * string containing an at-sign.
 */
final class CustomerStepRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:191'],

            /*
             * `email:rfc,dns` would reject an address whose domain has no MX
             * record — which happens for valid addresses behind misconfigured
             * or slow DNS, and turns a lost checkout into an unexplainable one.
             * `rfc` alone accepts anything deliverable; a bounced confirmation
             * is recoverable, a rejected checkout is not.
             */
            'email' => ['required', 'email:rfc', 'max:191'],

            'phone' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter your name.',
            'email.required' => 'Enter an email address so we can send your confirmation.',
            'email.email' => 'Enter a valid email address.',
        ];
    }
}
