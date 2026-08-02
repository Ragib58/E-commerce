<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Setting;
use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates a brand asset upload (logo, light/dark logo, favicon).
 *
 * The target setting is named in the route, and the upload is refused unless
 * that setting exists *and* is declared as an image type — otherwise a caller
 * could point an upload at, say, `theme.primary_color` and store a file path
 * where the storefront expects a hex colour.
 */
final class UploadSettingMediaRequest extends FormRequest
{
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
            'file' => MediaService::imageRules(required: true),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $setting = $this->setting();

                if ($setting === null) {
                    $validator->errors()->add('key', "The setting [{$this->settingKey()}] does not exist.");

                    return;
                }

                if (! $setting->type->isFileReference()) {
                    $validator->errors()->add(
                        'key',
                        "The setting [{$setting->key}] does not accept a file upload."
                    );
                }
            },
        ];
    }

    public function settingKey(): string
    {
        return (string) $this->route('key');
    }

    public function setting(): ?Setting
    {
        /** @var Setting|null $setting */
        $setting = Setting::query()->where('key', $this->settingKey())->first();

        return $setting;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxKb = (int) config('filesystems.uploads.max_image_size', 4096);

        return [
            'file.required' => 'Choose a file to upload.',
            'file.mimes' => 'The file must be an image. Allowed formats: '
                .implode(', ', (array) config('filesystems.uploads.image_mimes', [])).'.',
            'file.max' => sprintf('The image may not be larger than %d MB.', (int) round($maxKb / 1024)),
        ];
    }
}
