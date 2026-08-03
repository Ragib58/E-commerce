<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a brand.
 */
final class StoreBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Brand::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:160'],
            'slug' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('brands', 'slug')],
            'description' => ['nullable', 'string', 'max:5000'],
            'logo' => MediaService::imageRules(),
            'meta_title' => ['nullable', 'string', 'max:255'],
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
            'slug.unique' => 'A brand with this slug already exists.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if ($this->hasFile('logo')) {
            $data['logo'] = $this->file('logo');
        }

        return $data;
    }
}
