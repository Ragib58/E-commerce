<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Product;
use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a product.
 *
 * Money arrives as integer minor units, matching how it is stored. Accepting
 * decimals here would mean parsing "19.99" into 1999 in the request layer,
 * where a float round-trip can land on 1998 — so the conversion is the client's
 * job, done once, on a value it already holds exactly.
 *
 * Cross-field rules that depend on the product *type* live in ProductService;
 * this validates each field's own shape.
 */
final class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'slug' => ['nullable', 'string', 'max:280', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')],

            // Generated from the name when omitted. Uppercase alphanumerics
            // plus separators — a SKU is scanned and typed by hand in a
            // warehouse, so spaces and punctuation invite errors.
            'sku' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('products', 'sku')],

            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:65535'],

            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')],

            'type' => ['sometimes', Rule::enum(ProductType::class)],

            // Minor units. The ceiling is a typo guard: a price above ~21m in
            // major units is far likelier to be a mis-keyed figure than real.
            'price' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'discount_price' => ['nullable', 'integer', 'min:0', 'lt:price'],
            'cost_price' => ['nullable', 'integer', 'min:0'],

            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_taxable' => ['sometimes', 'boolean'],

            'stock' => ['sometimes', 'integer', 'min:0'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'allow_backorder' => ['sometimes', 'boolean'],

            // Grams and millimetres, as integers.
            'weight' => ['nullable', 'integer', 'min:0'],
            'length' => ['nullable', 'integer', 'min:0'],
            'width' => ['nullable', 'integer', 'min:0'],
            'height' => ['nullable', 'integer', 'min:0'],

            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
            'is_featured' => ['sometimes', 'boolean'],
            'is_new_arrival' => ['sometimes', 'boolean'],
            'is_best_seller' => ['sometimes', 'boolean'],

            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'og_image' => MediaService::imageRules(),
            'video_url' => ['nullable', 'url', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sku.unique' => 'A product with this SKU already exists.',
            'sku.regex' => 'A SKU may contain only letters, numbers, dots, underscores, and hyphens.',
            'slug.unique' => 'A product with this slug already exists.',
            'discount_price.lt' => 'The discount price must be lower than the regular price.',
            'price.required' => 'A price is required, even if it is zero.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if ($this->hasFile('og_image')) {
            $data['og_image'] = $this->file('og_image');
        }

        return $data;
    }
}
