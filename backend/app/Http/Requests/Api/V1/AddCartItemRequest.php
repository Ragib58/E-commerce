<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Add a product to the cart.
 *
 * Note what is absent: `price`, `discount`, `total`, `subtotal`. There is no
 * validation rule rejecting a submitted price because there is no field to
 * submit one into — `validated()` returns only the keys below, so anything else
 * in the body is discarded before it reaches the service.
 *
 * That is the stronger form of "never trust frontend price". A rule comparing a
 * client's price against the catalog would work, but it is a check that can be
 * omitted on the next endpoint someone adds. Having no price field anywhere
 * means the mistake is not available to make.
 *
 * Existence and availability are *not* validated here. Whether a product is
 * published, whether the chosen variant belongs to it, and whether enough stock
 * remains are all decisions that depend on catalog state at the moment of the
 * write — CartService checks them inside its transaction, where the answer
 * cannot change between the check and the insert.
 */
final class AddCartItemRequest extends FormRequest
{
    /**
     * Open to guests. The cart itself is the authorization boundary: a request
     * can only ever act on the cart its token or session resolves to.
     */
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
            /*
             * A slug or a uuid. The storefront holds a uuid on a product card
             * and a slug in the URL; accepting both saves a lookup round trip
             * on every add-to-cart.
             */
            'product' => ['required', 'string', 'max:255'],

            // Required for variable products, refused for the rest — a rule
            // that depends on the product's type, so it lives in the service.
            'variant' => ['nullable', 'string', 'max:64'],

            // Clamped again in the service; this bound exists so an absurd
            // value is refused with a field error rather than silently reduced.
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:99'],

            /*
             * Personalisation for customizable products — an engraving, a gift
             * message. Bounded in both breadth and depth: this is free-form
             * client input heading for a JSON column, and an unbounded object
             * is a cheap way to fill the table.
             */
            'options' => ['sometimes', 'nullable', 'array', 'max:20'],
            'options.*' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product.required' => 'Choose a product to add to your cart.',
            'quantity.max' => 'You can add at most 99 of a single item.',
        ];
    }
}
