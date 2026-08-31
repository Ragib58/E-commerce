<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Checkout;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Checkout step 3 — where the invoice goes.
 *
 * `same_as_shipping` is an explicit boolean, not an inferred one. Most shoppers
 * bill to their delivery address and retyping it is the friction that loses
 * checkouts — but the flag must be *sent*, because a skipped step and a
 * deliberate "same address" would otherwise be indistinguishable, and the step
 * guard would let the former through.
 *
 * The address fields are `required_if` rather than plain `required`: a shopper
 * who ticked the box has no billing address to submit, and demanding one would
 * make the tick useless.
 */
final class BillingAddressRequest extends FormRequest
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
        $rules = [
            'same_as_shipping' => ['required', 'boolean'],
        ];

        /*
         * Each address rule gains `required_if`, and its `required` is dropped.
         *
         * Rewritten rather than declared twice so the two steps still share one
         * definition — a billing address that accepted a longer street line
         * than the shipping step would be a divergence nobody notices until a
         * row is truncated.
         */
        foreach ($this->addressRules('address') as $field => $fieldRules) {
            $rewritten = array_values(array_filter(
                $fieldRules,
                static fn (string $rule): bool => $rule !== 'required',
            ));

            if (in_array('required', $fieldRules, strict: true)) {
                array_unshift($rewritten, 'required_if:same_as_shipping,false,0');
            } else {
                array_unshift($rewritten, 'nullable');
            }

            $rules[$field] = $rewritten;
        }

        return $rules;
    }

    /**
     * Whether the shopper chose to reuse their delivery address.
     */
    public function sameAsShipping(): bool
    {
        return $this->boolean('same_as_shipping');
    }

    /**
     * The submitted billing address, or null when reusing the shipping one.
     *
     * @return array<string, mixed>|null
     */
    public function address(): ?array
    {
        if ($this->sameAsShipping()) {
            return null;
        }

        /** @var array<string, mixed>|null $address */
        $address = $this->validated()['address'] ?? null;

        return $address;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge($this->addressMessages('address'), [
            'address.first_name.required_if' => 'Enter a first name for the billing address.',
            'address.last_name.required_if' => 'Enter a last name for the billing address.',
            'address.line1.required_if' => 'Enter a street address for billing.',
            'address.city.required_if' => 'Enter a city or town for billing.',
            'address.country.required_if' => 'Choose a billing country.',
        ]);
    }
}
