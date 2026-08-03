<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a variant.
 */
final class UpdateVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        $variant = $this->route('variant');

        return $this->user()?->can('update', $variant?->product) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $variantId = $this->route('variant')?->id;

        return [
            'sku' => [
                'sometimes',
                'string',
                'max:64',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('product_variants', 'sku')->ignore($variantId),
            ],

            'attribute_value_ids' => ['sometimes', 'array', 'min:1'],
            'attribute_value_ids.*' => ['integer', Rule::exists('attribute_values', 'id')],

            // Explicit null reverts to the product's price.
            'price' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2147483647'],
            'discount_price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'cost_price' => ['sometimes', 'nullable', 'integer', 'min:0'],

            'stock' => ['sometimes', 'integer', 'min:0'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'allow_backorder' => ['sometimes', 'boolean'],

            'image' => MediaService::imageRules(),

            'weight' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'length' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'width' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'height' => ['sometimes', 'nullable', 'integer', 'min:0'],

            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if ($this->hasFile('image')) {
            $data['image'] = $this->file('image');
        }

        return $data;
    }
}
