<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\SettingGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PublicSettingsRequest;
use App\Services\SettingsService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Public, unauthenticated access to admin-managed storefront configuration.
 *
 * This is the endpoint that makes the frontend fully dynamic: company name,
 * logo, favicon, brand colours, contact details, and social links are all
 * served from here, so none of them are ever hardcoded in the Next.js app.
 */
final class SettingsController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * All publicly-exposable settings, grouped.
     *
     * Optionally narrowed to one group via `?group=branding`, which lets a
     * page fetch only what it renders instead of the whole payload.
     */
    public function index(PublicSettingsRequest $request): JsonResponse
    {
        $group = $request->group();

        $data = $group instanceof SettingGroup
            ? [$group->value => $this->settings->group($group)]
            : $this->settings->publicGrouped();

        return $this->successResponse(
            data: $data,
            message: 'Settings retrieved successfully.',
            meta: [
                // The frontend keys its cache on this, so a settings change
                // produces a new cache key and cannot serve stale branding.
                'version' => $this->settings->version(),
                'groups' => array_keys($data),
            ],
        );
    }
}
