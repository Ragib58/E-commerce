<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Update a product.
 *
 * Every rule is `sometimes`: a PATCH that carries only `status` must not be
 * rejected for omitting `price`.
 */
final class UpdateProductRequest extends FormRequest
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
        $productId = $this->route('product')?->id;

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'slug' => [
                'sometimes',
                'string',
                'max:280',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('products', 'slug')->ignore($productId),
            ],
            'sku' => [
                'sometimes',
                'string',
                'max:64',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('products', 'sku')->ignore($productId),
            ],

            'short_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:65535'],

            'category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')],
            'brand_id' => ['sometimes', 'nullable', 'integer', Rule::exists('brands', 'id')],

            'type' => ['sometimes', Rule::enum(ProductType::class)],

            'price' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'discount_price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'cost_price' => ['sometimes', 'nullable', 'integer', 'min:0'],

            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'is_taxable' => ['sometimes', 'boolean'],

            'stock' => ['sometimes', 'integer', 'min:0'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'allow_backorder' => ['sometimes', 'boolean'],

            'weight' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'length' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'width' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'height' => ['sometimes', 'nullable', 'integer', 'min:0'],

            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
            'is_featured' => ['sometimes', 'boolean'],
            'is_new_arrival' => ['sometimes', 'boolean'],
            'is_best_seller' => ['sometimes', 'boolean'],

            'meta_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'og_image' => MediaService::imageRules(),
            'video_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ];
    }

    /**
     * Compare a submitted discount against the stored price.
     *
     * The `lt:price` rule used on create cannot work here: a PATCH that sends
     * only `discount_price` has no `price` field to compare against, and the
     * rule would silently pass — letting a discount exceed the price it
     * discounts.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('discount_price') || $this->input('discount_price') === null) {
                return;
            }

            $price = $this->has('price')
                ? (int) $this->input('price')
                : (int) ($this->route('product')?->price ?? 0);

            if ((int) $this->input('discount_price') >= $price) {
                $validator->errors()->add(
                    'discount_price',
                    'The discount price must be lower than the regular price.',
                );
            }
        });
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
