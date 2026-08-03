<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\ProductStatus;
use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a brand. Every field is optional; absent means "unchanged".
 */
final class UpdateBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('brand')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $brandId = $this->route('brand')?->id;

        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:160'],
            'slug' => [
                'sometimes',
                'string',
                'max:180',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('brands', 'slug')->ignore($brandId),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'logo' => MediaService::imageRules(),
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

        if ($this->hasFile('logo')) {
            $data['logo'] = $this->file('logo');
        }

        return $data;
    }
}
