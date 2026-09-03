<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Move an order to a new status.
 *
 * The rule bounds the value to a status that exists. Whether *this* order may
 * move to it is not checked here: legality depends on the order's current
 * state, which can change between validation and the write, so the transition
 * map is consulted inside OrderService's locked transaction. A rule here would
 * be a second, weaker copy of that check — and the weaker copy is the one that
 * silently disagrees.
 */
final class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware carries `permission:update_orders`; the policy adds
        // the per-record checks. Nothing further is decided here.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(OrderStatus::values())],

            /*
             * Why the status changed. Optional, but it lands in the audit trail
             * — "who cancelled this and why" is the question the history exists
             * to answer, and half of it is unanswerable without this.
             */
            'comment' => ['nullable', 'string', 'max:512'],

            /*
             * Whether to return the units to the shelf.
             *
             * Defaults to true in the controller rather than here: a cancelled
             * order normally restocks, and an admin who omits the field means
             * the ordinary case. Sending `false` is the deliberate exception —
             * goods damaged in the warehouse, which must not go back on sale.
             */
            'restock' => ['sometimes', 'boolean'],

            /*
             * Courier and tracking details, accepted alongside a move to
             * Shipped so the warehouse records all three in one action rather
             * than shipping first and then remembering the details. Free text
             * rather than a foreign key — see the courier migration for why a
             * courier is a fact printed on the parcel, not a reference to a
             * row that might later be renamed.
             */
            'courier_name' => ['nullable', 'string', 'max:128'],
            'tracking_number' => ['nullable', 'string', 'max:128'],
            'tracking_url' => ['nullable', 'url', 'max:512'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Choose a status.',
            'status.in' => 'That is not a valid order status.',
            'tracking_url.url' => 'Enter a valid tracking URL.',
        ];
    }
}
