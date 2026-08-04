<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\SectionType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Update a homepage section.
 *
 * `type` is deliberately absent and immutable. Changing a testimonial block
 * into a product rail would leave settings from the old type behind, and the
 * merge-on-update behaviour means they would never be cleared — the operator
 * deletes the section and adds the right one instead, which is one extra click
 * and no ambiguity.
 */
final class UpdateHomepageSectionRequest extends FormRequest
{
    use SectionSettingsRules;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('section')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'heading' => ['sometimes', 'nullable', 'string', 'max:200'],
            'subheading' => ['sometimes', 'nullable', 'string', 'max:500'],

            'settings' => ['sometimes', 'array'],

            'background_color' => ['sometimes', 'nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'container_width' => ['sometimes', 'nullable', Rule::in(['default', 'wide', 'full', 'narrow'])],

            'is_enabled' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],

            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
        // Rules come from the *stored* type, not from the request: type is
        // immutable, so the section on record is the authority.
        ], $this->settingsRules($this->storedType()));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $section = $this->route('section');

            $startsAt = $this->has('starts_at')
                ? $this->resolveDate($this->input('starts_at'))
                : $section?->starts_at;

            $endsAt = $this->has('ends_at')
                ? $this->resolveDate($this->input('ends_at'))
                : $section?->ends_at;

            if ($startsAt !== null && $endsAt !== null && $endsAt->lessThanOrEqualTo($startsAt)) {
                $validator->errors()->add('ends_at', 'The end date must be later than the start date.');
            }
        });
    }

    private function storedType(): ?SectionType
    {
        return $this->route('section')?->type;
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
        return array_merge($this->settingsMessages(), [
            'background_color.regex' => 'The background colour must be a hex value such as #f5f5f5.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }
}
