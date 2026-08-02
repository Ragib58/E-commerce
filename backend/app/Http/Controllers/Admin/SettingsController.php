<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\SettingGroup;
use App\Enums\SettingType;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\MediaService;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * Server-rendered settings management for the Blade admin panel.
 *
 * Shares SettingsService with the API, so a change saved here follows the same
 * transaction, the same cache flush, and the same SettingsUpdated event that
 * revalidates the Next.js storefront. There is no second write path.
 *
 * The form is generated from the settings table rather than hardcoded: each
 * row's `type` selects the input control and its validation rule, so a setting
 * added by a later phase appears in the panel with no change to this file or
 * its views.
 */
final class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * Render one group's editable fields.
     *
     * Defaults to the first group rather than showing everything at once: the
     * full set spans a dozen groups, and a single page of every field would be
     * unusable and would submit far more than the admin intended to change.
     */
    public function index(Request $request): View
    {
        $active = SettingGroup::tryFrom((string) $request->query('group')) ?? SettingGroup::General;

        $grouped = $this->settings->allForAdmin();

        return view('admin.settings.index', [
            'companyName' => $this->settings->get('general.company_name', config('app.name')),
            'groups' => SettingGroup::cases(),
            'activeGroup' => $active,
            'settings' => $grouped[$active->value] ?? collect(),
            'maxUploadKb' => (int) config('filesystems.uploads.max_image_size', 4096),
            'acceptedMimes' => (array) config('filesystems.uploads.image_mimes', []),
        ]);
    }

    /**
     * Persist the submitted values for one group.
     *
     * Only keys that actually exist and belong to the submitted group are
     * written. A crafted payload naming a key from another group — or a key
     * that does not exist — is rejected rather than silently creating a row.
     */
    public function update(Request $request, string $group): RedirectResponse
    {
        $settingGroup = SettingGroup::tryFrom($group);

        if ($settingGroup === null) {
            abort(404);
        }

        /** @var array<string, mixed> $submitted */
        $submitted = (array) $request->input('settings', []);

        /** @var Collection<string, Setting> $editable */
        $editable = Setting::query()
            ->group($settingGroup)
            ->get()
            ->keyBy('key')
            // File settings are handled by the upload endpoints, not this form.
            ->reject(fn (Setting $setting): bool => $setting->type->isFileReference());

        $values = [];
        $rules = [];
        $attributes = [];

        foreach ($editable as $key => $setting) {
            // A Boolean renders as a checkbox, and an unchecked box submits
            // nothing at all. Treating "absent" as "unchanged" would make it
            // impossible to ever turn a toggle off, so booleans are read as
            // false when missing while other types are skipped.
            if ($setting->type === SettingType::Boolean) {
                $values[$key] = $request->boolean("settings.{$key}");

                continue;
            }

            if (! array_key_exists($key, $submitted)) {
                continue;
            }

            $value = $submitted[$key];

            // Empty text inputs mean "unset", stored as NULL so the frontend's
            // fallback applies rather than rendering an empty string.
            $values[$key] = $value === '' ? null : $value;

            // Setting keys contain dots, which the validator otherwise reads as
            // nested-array traversal — `settings.theme.primary_color` would
            // look for a nested array, find nothing, and pass every rule
            // silently. Escaping the dot makes it a literal key segment.
            $escaped = 'settings.'.str_replace('.', '\.', $key);

            $rules[$escaped] = $setting->type->validationRules();
            $attributes[$escaped] = $setting->label ?? $key;
        }

        Validator::make(
            ['settings' => $values],
            $rules,
            ['settings.*.regex' => 'The :attribute must be a valid hex colour, for example #2563eb.'],
            $attributes
        )->validate();

        $this->settings->setMany($values);

        return redirect()
            ->route('admin.settings.index', ['group' => $settingGroup->value])
            ->with('status', $settingGroup->label().' settings saved. The storefront has been updated.');
    }

    /**
     * Upload a brand asset and point the named setting at it.
     */
    public function uploadMedia(Request $request, string $key): RedirectResponse
    {
        $setting = $this->findMediaSetting($key);

        $request->validate(
            ['file' => MediaService::imageRules(required: true)],
            [
                'file.mimes' => 'The file must be an image. Allowed formats: '
                    .implode(', ', (array) config('filesystems.uploads.image_mimes', [])).'.',
                'file.max' => sprintf(
                    'The image may not be larger than %d MB.',
                    (int) round(((int) config('filesystems.uploads.max_image_size', 4096)) / 1024)
                ),
            ]
        );

        $this->settings->setMedia($key, $request->file('file'));

        return redirect()
            ->route('admin.settings.index', ['group' => $setting->group->value])
            ->with('status', ($setting->label ?? $key).' uploaded.');
    }

    /**
     * Clear a brand asset and delete the stored file.
     */
    public function destroyMedia(string $key): RedirectResponse
    {
        $setting = $this->findMediaSetting($key);

        $this->settings->clearMedia($key);

        return redirect()
            ->route('admin.settings.index', ['group' => $setting->group->value])
            ->with('status', ($setting->label ?? $key).' removed.');
    }

    /**
     * Drop every cached settings payload and re-announce the change.
     *
     * Manual escape hatch for when the settings table has been written to
     * outside the service (a direct SQL fix, a restored backup).
     */
    public function flushCache(): RedirectResponse
    {
        $this->settings->flush();

        return back()->with('status', 'Settings cache cleared and the storefront revalidated.');
    }

    private function findMediaSetting(string $key): Setting
    {
        /** @var Setting|null $setting */
        $setting = Setting::query()->where('key', $key)->first();

        abort_if($setting === null || ! $setting->type->isFileReference(), 404);

        return $setting;
    }
}
