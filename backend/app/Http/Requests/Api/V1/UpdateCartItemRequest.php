<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Change a cart line's quantity.
 *
 * Quantity is the only mutable field. Changing which product or variant a line
 * refers to is not an edit — it is removing one thing and adding another, and
 * modelling it as an update would mean this endpoint could re-point a line at a
 * different product while keeping whatever else the line carried.
 *
 * As with AddCartItemRequest, no price field exists.
 */
final class UpdateCartItemRequest extends FormRequest
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
            /*
             * Zero is permitted and means "remove this line".
             *
             * A shopper who clears the field and types 0 has expressed removal;
             * rejecting it and making them find a separate button is friction
             * for no gain. CartService performs the delete.
             */
            'quantity' => ['required', 'integer', 'min:0', 'max:99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.max' => 'You can have at most 99 of a single item.',
        ];
    }
}
