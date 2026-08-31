<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Issue a refund against an order.
 *
 * ## What an admin may choose, and what they may not
 *
 * They choose **which lines** and **how many units**, or an order-level amount.
 * They do not choose what a unit is worth: a line refund's value is computed by
 * RefundService from the order's stored unit price plus its share of tax. An
 * `amount` sent alongside `lines` is ignored for that reason.
 *
 * The bare `amount` path exists for refunds that do not correspond to goods —
 * a waived shipping fee, a goodwill credit — and is bounded by the order's
 * remaining refundable balance inside the service's locked transaction. A rule
 * here could not enforce that ceiling correctly: two admins refunding at once
 * would both validate against the same stale balance.
 */
final class RefundOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware carries `permission:refund_orders`.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Minor units. Omitted means "the whole remaining balance", which
             * is the common case — an admin refunding an entire order should
             * not have to type its total and risk a typo that under-refunds.
             */
            'amount' => ['nullable', 'integer', 'min:1'],

            'lines' => ['nullable', 'array', 'max:100'],
            'lines.*.order_item_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],

            /*
             * Required, unlike most free-text fields here. A refund moves money
             * out of the business, and an unexplained one is the entry an audit
             * stops at.
             */
            'reason' => ['required', 'string', 'max:512'],

            /*
             * Whether the goods return to sellable stock.
             *
             * Explicit rather than implied by the refund itself: refunding a
             * damaged item the store does not want back is ordinary, and
             * silently restocking it would put a broken product on sale.
             */
            'restock' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Record why this refund is being issued.',
            'amount.min' => 'A refund must be for a positive amount.',
            'lines.*.quantity.min' => 'Refund at least one unit of each selected item.',
        ];
    }
}
