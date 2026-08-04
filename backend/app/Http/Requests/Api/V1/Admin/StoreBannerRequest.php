<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\BannerPlacement;
use App\Enums\PublishStatus;
use App\Models\Banner;
use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a banner.
 *
 * The primary image is required here and optional on update, which is the one
 * meaningful difference between the two requests: a banner with no image has
 * nothing to render, but an edit that changes only the schedule must not force
 * the operator to re-upload it.
 */
final class StoreBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Banner::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:180'],
            'subtitle' => ['nullable', 'string', 'max:320'],

            'image' => MediaService::imageRules(required: true),
            'mobile_image' => MediaService::imageRules(),

            // Encouraged rather than required: the model falls back to the
            // title so an image is never announced as unlabelled, and blocking
            // a save over alt text trains operators to type "banner".
            'alt_text' => ['nullable', 'string', 'max:255'],

            /*
             * Links are constrained to http/https or a site-relative path.
             * `url` alone would admit `javascript:` — which is a stored XSS
             * payload the moment it reaches an href.
             */
            'link_url' => ['nullable', 'string', 'max:512', 'regex:/^(https?:\/\/|\/)[^\s<>"]*$/i'],
            'link_label' => ['nullable', 'string', 'max:80'],
            'link_external' => ['sometimes', 'boolean'],

            'placement' => ['required', Rule::enum(BannerPlacement::class)],
            'status' => ['sometimes', Rule::enum(PublishStatus::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],

            'starts_at' => ['nullable', 'date'],
            // Must not precede the start. A window that closes before it opens
            // is never intentional, and produces a banner that silently never
            // appears — the hardest kind of configuration bug to notice.
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'link_url.regex' => 'The link must be a full http(s) address or a path beginning with “/”.',
            'ends_at.after' => 'The end date must be later than the start date.',
        ];
    }

    /**
     * Validated input with uploads attached.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        foreach (['image', 'mobile_image'] as $field) {
            if ($this->hasFile($field)) {
                $data[$field] = $this->file($field);
            }
        }

        return $data;
    }
}
