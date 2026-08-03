<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\StockMovementReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adjust stock for a product or one of its variants.
 *
 * Two modes, deliberately distinct:
 *
 *   mode=delta      `quantity` is a signed change (+50 received, -3 damaged).
 *   mode=absolute   `quantity` is the counted figure on the shelf.
 *
 * Conflating them is how stock takes go wrong: an operator who counts 40 and
 * submits it as a delta *adds* 40 to a figure they had just proved wrong.
 */
final class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('adjustStock', $this->route('product')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['delta', 'absolute'])],

            /*
             * Signed for a delta, so `min` cannot be used here — the service
             * rejects zero, and rejects a delta that would drive stock below
             * zero without backorder enabled.
             */
            'quantity' => ['required', 'integer', 'between:-1000000,1000000'],

            /*
             * Restricted to reasons an operator may legitimately post. `sale`
             * and `return` are written only by the order pipeline; accepting
             * them here would let a manual entry masquerade as a sale and
             * corrupt reconciliation against actual orders.
             */
            'reason' => [
                'required',
                Rule::in(array_map(
                    static fn (StockMovementReason $reason): string => $reason->value,
                    StockMovementReason::manuallySelectable(),
                )),
            ],

            // Which variant to adjust. Required when the product is variable —
            // enforced in the controller, which knows the product's type.
            'variant_id' => ['nullable', 'string', Rule::exists('product_variants', 'uuid')],

            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mode.in' => 'The adjustment mode must be either "delta" or "absolute".',
            'reason.in' => 'That reason cannot be selected manually. Sales and returns are recorded by the order system.',
            'variant_id.exists' => 'The selected variant does not exist.',
        ];
    }

    public function isAbsolute(): bool
    {
        return $this->validated('mode') === 'absolute';
    }

    public function reason(): StockMovementReason
    {
        return StockMovementReason::from((string) $this->validated('reason'));
    }
}
