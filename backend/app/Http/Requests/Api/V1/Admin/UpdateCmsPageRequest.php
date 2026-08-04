<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\PublishStatus;
use App\Services\MediaService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Update a CMS page.
 *
 * Note what is *not* restricted: a system page's title, slug, and body are all
 * editable. `is_system` is a delete guard, not a read-only flag — an operator
 * must be able to write their own refund policy, and one they cannot edit is
 * worse than none.
 */
final class UpdateCmsPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('page')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $pageId = $this->route('page')?->getKey();

        return [
            'title' => ['sometimes', 'string', 'min:2', 'max:200'],

            'slug' => [
                'sometimes',
                'string',
                'max:220',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn(['products', 'categories', 'brands', 'admin', 'api', 'cart', 'checkout', 'account', 'search']),
                Rule::unique('cms_pages', 'slug')->ignore($pageId),
            ],

            'excerpt' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'content' => ['sometimes', 'nullable', 'string', 'max:' . (int) config('content.html.max_length', 200000)],

            'featured_image' => MediaService::imageRules(),
            'og_image' => MediaService::imageRules(),

            'seo_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'seo_keywords' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_indexable' => ['sometimes', 'boolean'],

            'status' => ['sometimes', Rule::enum(PublishStatus::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],

            // Compared against the merged state in withValidator — see
            // UpdateBannerRequest for why the `after` rule cannot be used on a
            // partial update.
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $page = $this->route('page');

            $startsAt = $this->has('starts_at')
                ? $this->resolveDate($this->input('starts_at'))
                : $page?->starts_at;

            $endsAt = $this->has('ends_at')
                ? $this->resolveDate($this->input('ends_at'))
                : $page?->ends_at;

            if ($startsAt !== null && $endsAt !== null && $endsAt->lessThanOrEqualTo($startsAt)) {
                $validator->errors()->add('ends_at', 'The end date must be later than the start date.');
            }
        });
    }

    private function resolveDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may contain only lowercase letters, numbers, and single hyphens.',
            'slug.unique' => 'A page with this slug already exists.',
            'slug.not_in' => 'This slug is reserved by the storefront. Choose another.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        foreach (['featured_image', 'og_image'] as $field) {
            if ($this->hasFile($field)) {
                $data[$field] = $this->file($field);
            }
        }

        return $data;
    }
}
