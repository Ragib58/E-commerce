<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\SettingGroup;
use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\Setting;
use App\Services\HealthCheckService;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;

/**
 * Admin panel entry point.
 *
 * Note that it consumes the same SettingsService the public API uses — the
 * admin panel and the storefront share one domain layer, so a settings change
 * made here follows the identical code path, including cache invalidation.
 */
final class DashboardController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly HealthCheckService $health,
    ) {
    }

    public function index(): View
    {
        $readiness = $this->health->readiness();

        return view('admin.dashboard', [
            'companyName' => $this->settings->get('general.company_name', config('app.name')),
            'settingsCount' => Setting::query()->count(),
            'publicSettingsCount' => Setting::query()->public()->count(),
            'menuCount' => Menu::query()->count(),
            'groups' => SettingGroup::cases(),
            'health' => $readiness,
        ]);
    }
}
