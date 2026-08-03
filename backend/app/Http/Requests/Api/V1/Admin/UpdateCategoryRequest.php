<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\ProductStatus;
use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a category.
 *
 * Every field is `sometimes`, so a PATCH carrying one key changes only that
 * key. An absent field means "leave it alone"; an explicit null means "clear
 * it" — a distinction the service relies on to tell a missing image from a
 * removed one.
 */
final class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('category')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $categoryId = $this->route('category')?->id;

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],

            'slug' => [
                'sometimes',
                'string',
                'max:180',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('categories', 'slug')->ignore($categoryId),
            ],

            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],

            // Nullable: clearing it promotes the category to a root.
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')],

            'image' => MediaService::imageRules(),
            'banner' => MediaService::imageRules(),

            'meta_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:320'],

            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        foreach (['image', 'banner'] as $field) {
            if ($this->hasFile($field)) {
                $data[$field] = $this->file($field);
            }
        }

        return $data;
    }
}
