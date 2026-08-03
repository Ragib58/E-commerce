<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a variant of a variable product.
 *
 * The combination rules — no duplicates, one value per attribute — are enforced
 * in VariantService, since they depend on the product's existing variants
 * rather than on this payload alone.
 */
final class StoreVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('product')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sku' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('product_variants', 'sku')],

            // The attribute values that define this combination. Required:
            // a variant with no options is indistinguishable from its product.
            'attribute_value_ids' => ['required', 'array', 'min:1'],
            'attribute_value_ids.*' => ['integer', Rule::exists('attribute_values', 'id')],

            // Null means "inherit the product's price" — distinct from 0.
            'price' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'discount_price' => ['nullable', 'integer', 'min:0'],
            'cost_price' => ['nullable', 'integer', 'min:0'],

            'stock' => ['sometimes', 'integer', 'min:0'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'allow_backorder' => ['sometimes', 'boolean'],

            'image' => MediaService::imageRules(),

            'weight' => ['nullable', 'integer', 'min:0'],
            'length' => ['nullable', 'integer', 'min:0'],
            'width' => ['nullable', 'integer', 'min:0'],
            'height' => ['nullable', 'integer', 'min:0'],

            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'attribute_value_ids.required' => 'Select the options that define this variant, such as a size and a colour.',
            'attribute_value_ids.*.exists' => 'One or more of the selected options does not exist.',
            'sku.unique' => 'A variant with this SKU already exists.',
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
