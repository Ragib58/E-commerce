<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\BannerPlacement;
use App\Enums\PublishStatus;
use App\Services\MediaService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Update a banner.
 *
 * Every field is `sometimes`: the panel saves one form section at a time, and a
 * scheduling change must not require resubmitting the image.
 */
final class UpdateBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('banner')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'min:2', 'max:180'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:320'],

            // Optional on update — see the class docblock. The service leaves
            // the existing file alone when the key is absent.
            'image' => MediaService::imageRules(),
            'mobile_image' => MediaService::imageRules(),

            'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],

            'link_url' => ['sometimes', 'nullable', 'string', 'max:512', 'regex:/^(https?:\/\/|\/)[^\s<>"]*$/i'],
            'link_label' => ['sometimes', 'nullable', 'string', 'max:80'],
            'link_external' => ['sometimes', 'boolean'],

            'placement' => ['sometimes', Rule::enum(BannerPlacement::class)],
            'status' => ['sometimes', Rule::enum(PublishStatus::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],

            'starts_at' => ['sometimes', 'nullable', 'date'],
            /*
             * No `after:starts_at` here.
             *
             * A partial update may send only `ends_at`, in which case the rule
             * has no `starts_at` field to compare against and passes
             * vacuously — while the *stored* start might well be later. The
             * comparison is done in withValidator() against the merged state
             * instead, which is the only place both values are known.
             */
            'ends_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * Validate the scheduling window against the state the save would produce.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $banner = $this->route('banner');

            $startsAt = $this->has('starts_at')
                ? $this->resolveDate($this->input('starts_at'))
                : $banner?->starts_at;

            $endsAt = $this->has('ends_at')
                ? $this->resolveDate($this->input('ends_at'))
                : $banner?->ends_at;

            if ($startsAt !== null && $endsAt !== null && $endsAt->lessThanOrEqualTo($startsAt)) {
                $validator->errors()->add(
                    'ends_at',
                    'The end date must be later than the start date.',
                );
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
            // The `date` rule has already flagged this; returning null keeps
            // the window check from adding a second, more confusing error.
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'link_url.regex' => 'The link must be a full http(s) address or a path beginning with “/”.',
        ];
    }

    /**
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
