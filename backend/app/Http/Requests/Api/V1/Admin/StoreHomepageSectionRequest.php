<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\SectionType;
use App\Models\HomepageSection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a homepage section.
 *
 * `settings` is validated against the *type*, because the payload of a hero
 * slider has nothing in common with that of a testimonial block. The per-type
 * rules live in SectionSettingsRules so the create and update requests cannot
 * drift apart.
 */
final class StoreHomepageSectionRequest extends FormRequest
{
    use SectionSettingsRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', HomepageSection::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'type' => ['required', Rule::enum(SectionType::class)],
            'name' => ['required', 'string', 'min:2', 'max:120'],

            'heading' => ['nullable', 'string', 'max:200'],
            'subheading' => ['nullable', 'string', 'max:500'],

            'settings' => ['sometimes', 'array'],

            // Presentation tokens, constrained rather than free text: these
            // reach a style attribute, and an unvalidated string there is a CSS
            // injection surface.
            'background_color' => ['nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'container_width' => ['nullable', Rule::in(['default', 'wide', 'full', 'narrow'])],

            'is_enabled' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],

            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ], $this->settingsRules($this->resolveType()));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge($this->settingsMessages(), [
            'background_color.regex' => 'The background colour must be a hex value such as #f5f5f5.',
            'ends_at.after' => 'The end date must be later than the start date.',
        ]);
    }

    private function resolveType(): ?SectionType
    {
        return SectionType::tryFrom((string) $this->input('type'));
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }
}
