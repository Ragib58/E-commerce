<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SettingGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateSettingsRequest;
use App\Http\Requests\Api\V1\Admin\UploadSettingMediaRequest;
use App\Http\Resources\Api\V1\SettingResource;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff-facing management of the dynamic settings that brand the storefront.
 *
 * Every route here sits behind `permission:manage_settings` (read paths also
 * accept `view_settings`), because this surface exposes private groups — mail
 * and payment configuration — that the public endpoint deliberately never
 * returns.
 *
 * Writes go through SettingsService, so an admin save takes the identical code
 * path as any other mutation: one transaction, one cache flush, one
 * SettingsUpdated event that revalidates the Next.js cache.
 */
final class SettingsManagementController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * Every setting, grouped, with the metadata needed to render an edit form.
     *
     * Optionally narrowed with `?group=theme`.
     */
    public function index(Request $request): JsonResponse
    {
        $group = $this->resolveGroup($request);

        $grouped = $this->settings->allForAdmin($group);

        $data = [];

        foreach ($grouped as $groupKey => $settings) {
            $enum = SettingGroup::tryFrom($groupKey);

            $data[$groupKey] = [
                'label' => $enum?->label() ?? $groupKey,
                'description' => $enum?->description(),
                'icon' => $enum?->icon(),
                'is_public' => $enum?->isPubliclyExposable() ?? false,
                'settings' => SettingResource::collection($settings)->resolve(),
            ];
        }

        return $this->successResponse(
            data: $data,
            message: 'Settings retrieved successfully.',
            meta: [
                'version' => $this->settings->version(),
                'groups' => array_keys($data),
            ],
        );
    }

    /**
     * The full catalogue of groups, for building the panel's tab strip without
     * hardcoding the list in the client.
     */
    public function groups(): JsonResponse
    {
        $data = array_map(
            static fn (SettingGroup $group): array => [
                'value' => $group->value,
                'label' => $group->label(),
                'description' => $group->description(),
                'icon' => $group->icon(),
                'is_public' => $group->isPubliclyExposable(),
            ],
            SettingGroup::cases()
        );

        return $this->successResponse(
            data: $data,
            message: 'Setting groups retrieved successfully.',
        );
    }

    /**
     * Bulk update. The whole submission is validated before anything is
     * written, so a single invalid colour cannot leave a half-applied theme.
     */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $values = $request->settings();

        $this->settings->setMany($values);

        return $this->successResponse(
            data: [
                'updated' => array_keys($values),
            ],
            message: 'Settings updated successfully.',
            meta: ['version' => $this->settings->version()],
        );
    }

    /**
     * Upload a brand asset (logo, light logo, dark logo, favicon) and point the
     * named setting at it. Any previously stored file is deleted.
     */
    public function uploadMedia(UploadSettingMediaRequest $request): JsonResponse
    {
        $url = $this->settings->setMedia(
            $request->settingKey(),
            $request->file('file'),
        );

        return $this->successResponse(
            data: [
                'key' => $request->settingKey(),
                'url' => $url,
            ],
            message: 'File uploaded successfully.',
            status: Response::HTTP_CREATED,
            meta: ['version' => $this->settings->version()],
        );
    }

    /**
     * Clear a brand asset, deleting the stored file.
     *
     * The setting row survives with a null value — the key is part of the
     * seeded schema and the admin form still needs to render its field.
     */
    public function destroyMedia(string $key): JsonResponse
    {
        /** @var Setting|null $setting */
        $setting = Setting::query()->where('key', $key)->first();

        if ($setting === null || ! $setting->type->isFileReference()) {
            return $this->errorResponse(
                message: "The setting [{$key}] is not an uploadable asset.",
                status: Response::HTTP_NOT_FOUND,
            );
        }

        $this->settings->clearMedia($key);

        return $this->successResponse(
            data: ['key' => $key, 'url' => null],
            message: 'File removed successfully.',
            meta: ['version' => $this->settings->version()],
        );
    }

    /**
     * Drop every cached settings payload.
     *
     * An escape hatch for the case where an external process has written to the
     * settings table directly and the cache is therefore stale.
     */
    public function flushCache(): JsonResponse
    {
        $this->settings->flush();

        return $this->successResponse(
            message: 'Settings cache cleared successfully.',
            meta: ['version' => $this->settings->version()],
        );
    }

    private function resolveGroup(Request $request): ?SettingGroup
    {
        $group = $request->query('group');

        return is_string($group) ? SettingGroup::tryFrom($group) : null;
    }
}
