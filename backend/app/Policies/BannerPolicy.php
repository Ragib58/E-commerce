<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionType;
use App\Models\Admin;
use App\Models\Banner;

/**
 * Authorization for promotional banners.
 *
 * `manage_banners` is separate from `manage_content` because the roles
 * genuinely differ: a marketing account schedules campaign imagery constantly,
 * while rewriting the terms and conditions is a rarer and more consequential
 * act. `manage_content` also grants banner access, since a content manager who
 * cannot place a hero image cannot build the page they are responsible for.
 */
final class BannerPolicy
{
    public function viewAny(Admin $actor): bool
    {
        return $actor->hasAnyPermission([
            PermissionType::ManageBanners,
            PermissionType::ManageContent,
            PermissionType::ViewSettings,
            PermissionType::ManageSettings,
        ]);
    }

    public function view(Admin $actor, Banner $banner): bool
    {
        return $this->viewAny($actor);
    }

    public function create(Admin $actor): bool
    {
        return $this->canWrite($actor);
    }

    public function update(Admin $actor, Banner $banner): bool
    {
        return $this->canWrite($actor);
    }

    public function delete(Admin $actor, Banner $banner): bool
    {
        return $this->canWrite($actor);
    }

    public function reorder(Admin $actor): bool
    {
        return $this->canWrite($actor);
    }

    private function canWrite(Admin $actor): bool
    {
        return $actor->hasAnyPermission([
            PermissionType::ManageBanners,
            PermissionType::ManageContent,
        ]);
    }
}
