<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/**
 * Validates a bulk settings save from the admin panel.
 *
 * Rules are derived from each setting's declared type at request time rather
 * than hardcoded. The settings table is admin-extensible — a new colour or
 * social link is an INSERT — so a static rule map would silently stop
 * validating any key added after this file was written.
 *
 * Unknown keys are rejected rather than ignored: accepting them would let a
 * caller create arbitrary settings rows through what is meant to be an update
 * endpoint, including one in a publicly-exposed group.
 */
final class UpdateSettingsRequest extends FormRequest
{
    /**
     * Route middleware (`permission:manage_settings`) is the real gate; this
     * returns true so an authorization failure surfaces as the middleware's
     * 403 rather than a less specific one from here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array', 'min:1'],
            'settings.*' => ['nullable'],
        ];
    }

    /**
     * Apply each setting's own type rules, and reject unknown keys.
     *
     * This runs as a `withValidator` hook rather than in rules(): the rules
     * depend on which keys were submitted, which means a database lookup, and
     * rules() is evaluated before the payload shape is known to be valid.
     *
     * The per-key rules are *added to the same validator* rather than checked
     * by a second one, so their failures land in the same error bag and the
     * request fails as a whole — the property the "no partial write" guarantee
     * rests on.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var mixed $submitted */
            $submitted = $this->input('settings');

            if (! is_array($submitted) || $submitted === []) {
                return;
            }

            /** @var Collection<string, Setting> $known */
            $known = Setting::query()->whereIn('key', array_keys($submitted))->get()->keyBy('key');

            $unknown = array_diff(array_keys($submitted), $known->keys()->all());

            foreach ($unknown as $key) {
                // Rejected rather than ignored: accepting unknown keys would
                // let this endpoint create arbitrary settings rows, including
                // one in a publicly-exposed group.
                $validator->errors()->add(
                    "settings.{$key}",
                    "The setting [{$key}] does not exist."
                );
            }

            if ($unknown !== []) {
                return;
            }

            $rules = [];

            foreach ($known as $key => $setting) {
                // Setting keys contain dots, which the validator otherwise
                // reads as nested-array traversal: `settings.theme.primary_color`
                // would look for $data['settings']['theme']['primary_color'],
                // find nothing, and pass every rule silently. Escaping the dot
                // makes it a literal key segment.
                $rules['settings.'.str_replace('.', '\.', $key)] = $setting->type->validationRules();
            }

            $typed = validator(
                ['settings' => $submitted],
                $rules,
                $this->messages(),
                $this->attributes()
            );

            foreach ($typed->errors()->messages() as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        });
    }

    /**
     * The submitted values, keyed by setting key.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        /** @var array<string, mixed> $settings */
        $settings = $this->validated()['settings'] ?? [];

        return $settings;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        // Present the admin's own label in error messages instead of the raw
        // dot-key, which is meaningless in the UI.
        return Setting::query()
            ->whereIn('key', array_keys((array) $this->input('settings', [])))
            ->get()
            ->mapWithKeys(fn (Setting $setting): array => [
                "settings.{$setting->key}" => $setting->label ?? $setting->key,
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'settings.*.regex' => 'The :attribute must be a valid hex colour, for example #2563eb.',
        ];
    }
}
