<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a category.
 *
 * Shape only. The tree rules — no cycles, valid parent — live in
 * CategoryService, because they depend on the stored hierarchy rather than on
 * the submitted fields alone.
 */
final class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Category::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],

            // Optional: derived from the name when absent. Constrained to slug
            // characters so a category can never occupy a URL that collides
            // with a route segment.
            'slug' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('categories', 'slug')],

            'description' => ['nullable', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],

            'image' => MediaService::imageRules(),
            'banner' => MediaService::imageRules(),

            'meta_title' => ['nullable', 'string', 'max:255'],
            // 160 is the practical ceiling before search engines truncate.
            'meta_description' => ['nullable', 'string', 'max:320'],

            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may contain only lowercase letters, numbers, and single hyphens.',
            'slug.unique' => 'A category with this slug already exists.',
            'parent_id.exists' => 'The selected parent category does not exist.',
        ];
    }

    /**
     * Validated input, with the uploaded files attached.
     *
     * Files do not appear in validated() — that returns only the rule-matched
     * scalars — so they are merged back in for the service.
     *
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
