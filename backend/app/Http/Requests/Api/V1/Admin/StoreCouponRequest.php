<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\CouponType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create a coupon.
 *
 * Every rule the brief lists is a field here, and every field maps directly to
 * a column CouponService reads at redemption — there is no rule expressed only
 * in this class. See Coupon's migration for why that matters: a coupon whose
 * logic lived in code rather than data would need a deploy for every new
 * promotion a store runs.
 */
final class StoreCouponRequest extends FormRequest
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
            'code' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('coupons', 'code')],
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:512'],

            'type' => ['required', Rule::enum(CouponType::class)],

            /*
             * Interpreted per `type`: a percentage up to 100, or minor units
             * with no upper bound beyond what fits the column. The distinct
             * ranges are why this is checked in withValidator rather than a
             * single static rule.
             */
            'value' => ['required', 'numeric', 'min:0.01'],

            'max_discount' => ['nullable', 'integer', 'min:1'],
            'min_order_amount' => ['nullable', 'integer', 'min:0'],

            'free_shipping' => ['sometimes', 'boolean'],

            /*
             * Scope. `applies_to_all` defaults true; when false, at least one
             * of products or categories must be supplied, checked below,
             * because an unscoped-but-not-all-products coupon discounts
             * nothing and is certainly a mistake rather than an intention.
             */
            'applies_to_all' => ['sometimes', 'boolean'],
            'products' => ['sometimes', 'array', 'max:500'],
            'products.*.id' => ['required_with:products', 'integer', 'exists:products,id'],
            'products.*.excluded' => ['sometimes', 'boolean'],
            'categories' => ['sometimes', 'array', 'max:200'],
            'categories.*.id' => ['required_with:categories', 'integer', 'exists:categories,id'],
            'categories.*.excluded' => ['sometimes', 'boolean'],
            'categories.*.includes_descendants' => ['sometimes', 'boolean'],

            'first_order_only' => ['sometimes', 'boolean'],

            'user_restricted' => ['sometimes', 'boolean'],
            'user_ids' => ['required_if:user_restricted,true', 'array', 'max:1000'],
            'user_ids.*' => ['integer', 'exists:users,id'],

            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],

            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_user_limit' => ['nullable', 'integer', 'min:1'],

            'is_active' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'The code may contain only letters, numbers, hyphens, and underscores.',
            'code.unique' => 'A coupon with this code already exists.',
            'expires_at.after' => 'The expiry must be after the start date.',
            'user_ids.required_if' => 'Select at least one customer for a user-restricted coupon.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = $this->input('type');
            $value = $this->input('value');

            /*
             * A percentage coupon means nothing above 100, and a store that
             * sets one to 150 almost certainly meant 15 or made a typo — this
             * catches it at the form rather than at the first customer who
             * receives more money than they spent.
             */
            if ($type === CouponType::Percentage->value && $value !== null && (float) $value > 100) {
                $validator->errors()->add('value', 'A percentage discount cannot exceed 100.');
            }

            if (
                $this->boolean('applies_to_all') === false
                && $this->input('products', []) === []
                && $this->input('categories', []) === []
            ) {
                $validator->errors()->add(
                    'applies_to_all',
                    'Select at least one product or category, or apply this coupon to the whole order.',
                );
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->safe()->only([
            'code', 'name', 'description', 'type', 'value', 'max_discount',
            'min_order_amount', 'free_shipping', 'applies_to_all',
            'first_order_only', 'user_restricted', 'starts_at', 'expires_at',
            'usage_limit', 'per_user_limit', 'is_active', 'is_public',
        ]);

        $data['created_by'] = $this->user('admin-api')?->getKey();

        return $data;
    }

    /**
     * @return array<int, array{id: int, excluded: bool}>
     */
    public function products(): array
    {
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'excluded' => (bool) ($row['excluded'] ?? false)],
            $this->input('products', []),
        );
    }

    /**
     * @return array<int, array{id: int, excluded: bool, includes_descendants: bool}>
     */
    public function categories(): array
    {
        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'excluded' => (bool) ($row['excluded'] ?? false),
                'includes_descendants' => (bool) ($row['includes_descendants'] ?? true),
            ],
            $this->input('categories', []),
        );
    }

    /**
     * @return array<int, int>
     */
    public function userIds(): array
    {
        return array_map('intval', $this->input('user_ids', []));
    }
}
