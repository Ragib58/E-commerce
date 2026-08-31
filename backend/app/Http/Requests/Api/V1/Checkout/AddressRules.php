<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Checkout;

/**
 * Validation rules for a postal address.
 *
 * Shared by the shipping and billing steps so the two cannot drift. They are
 * the same kind of thing and must accept the same inputs — a billing address
 * rejected for a format the shipping step allows is a checkout the shopper
 * cannot complete and cannot diagnose.
 *
 * ## Deliberately permissive
 *
 * Address formats differ enormously between countries. A schema modelled on one
 * country's postal system rejects valid addresses elsewhere, and every rejection
 * is a lost sale for a formatting opinion. So only `line1`, `city`, and
 * `country` are required — the parts every country has — and `state` and
 * `postal_code` are optional, because plenty of places have neither.
 *
 * The bounds that *are* enforced are length limits, which exist to match the
 * columns rather than to express a view about what an address should look like.
 */
trait AddressRules
{
    /**
     * @param  string  $prefix  Field prefix, e.g. `billing_address`.
     * @return array<string, mixed>
     */
    protected function addressRules(string $prefix = ''): array
    {
        $key = $prefix === '' ? '' : $prefix.'.';

        return [
            $key.'first_name' => ['required', 'string', 'max:96'],
            $key.'last_name' => ['required', 'string', 'max:96'],
            $key.'company' => ['nullable', 'string', 'max:191'],

            /*
             * Contact for *this address*, not for the account. A courier rings
             * the number attached to the delivery, which may not be the
             * account's when the parcel is going to someone else.
             */
            $key.'phone' => ['nullable', 'string', 'max:32'],
            $key.'email' => ['nullable', 'email:rfc', 'max:191'],

            $key.'line1' => ['required', 'string', 'max:255'],
            $key.'line2' => ['nullable', 'string', 'max:255'],
            $key.'city' => ['required', 'string', 'max:128'],

            // Optional: many countries have no state or province in an address.
            $key.'state' => ['nullable', 'string', 'max:128'],

            // Optional for the same reason — several countries have no postcode
            // system at all, and requiring one locks them out entirely.
            $key.'postal_code' => ['nullable', 'string', 'max:32'],

            /*
             * ISO 3166-1 alpha-2, exactly two letters.
             *
             * The one strictly-formatted field, because it is the one the
             * system reasons about: shipping availability and tax both compare
             * against it, and a free-text "United Kingdom" versus "UK" versus
             * "GB" would make those comparisons unreliable.
             */
            $key.'country' => ['required', 'string', 'size:2', 'alpha'],

            $key.'delivery_instructions' => ['nullable', 'string', 'max:512'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function addressMessages(string $prefix = ''): array
    {
        $key = $prefix === '' ? '' : $prefix.'.';

        return [
            $key.'first_name.required' => 'Enter a first name.',
            $key.'last_name.required' => 'Enter a last name.',
            $key.'line1.required' => 'Enter a street address.',
            $key.'city.required' => 'Enter a city or town.',
            $key.'country.required' => 'Choose a country.',
            $key.'country.size' => 'Use a two-letter country code.',
        ];
    }
}
