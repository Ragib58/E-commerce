<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\PublishStatus;
use App\Models\CmsPage;
use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a CMS page.
 *
 * Shape only. Sanitisation of `content` is not a validation concern — it
 * *transforms* the input rather than accepting or rejecting it, and it happens
 * in CmsPageService so that every write path is covered, not just this one.
 */
final class StoreCmsPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CmsPage::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:200'],

            /*
             * Optional — derived from the title when absent.
             *
             * The reserved list keeps a page from occupying a URL the
             * storefront already routes: a page slugged "products" would be
             * unreachable, and diagnosing why costs far more than refusing it
             * here.
             */
            'slug' => [
                'nullable',
                'string',
                'max:220',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn(['products', 'categories', 'brands', 'admin', 'api', 'cart', 'checkout', 'account', 'search']),
                Rule::unique('cms_pages', 'slug'),
            ],

            'excerpt' => ['nullable', 'string', 'max:1000'],
            'content' => ['nullable', 'string', 'max:' . (int) config('content.html.max_length', 200000)],

            'featured_image' => MediaService::imageRules(),
            'og_image' => MediaService::imageRules(),

            'seo_title' => ['nullable', 'string', 'max:255'],
            // 160 is where search results truncate; the column allows more so
            // an operator is warned rather than blocked.
            'seo_description' => ['nullable', 'string', 'max:500'],
            'seo_keywords' => ['nullable', 'string', 'max:500'],
            'is_indexable' => ['sometimes', 'boolean'],

            'status' => ['sometimes', Rule::enum(PublishStatus::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],

            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ];
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
            'ends_at.after' => 'The end date must be later than the start date.',
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
