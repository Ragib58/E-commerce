<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionType;
use App\Models\Admin;
use App\Models\HomepageSection;

/**
 * Authorization for the homepage builder.
 *
 * Gated on `manage_content`, the same permission as CMS pages: composing the
 * homepage and writing its supporting pages are one editorial job. Banner
 * managers are deliberately *not* admitted — placing a slide into an existing
 * hero section is banner work, but adding or removing sections restructures the
 * page itself.
 */
final class HomepageSectionPolicy
{
    public function viewAny(Admin $actor): bool
    {
        return $actor->hasAnyPermission([
            PermissionType::ManageContent,
            PermissionType::ManageBanners,
            PermissionType::ViewSettings,
            PermissionType::ManageSettings,
        ]);
    }

    public function view(Admin $actor, HomepageSection $section): bool
    {
        return $this->viewAny($actor);
    }

    public function create(Admin $actor): bool
    {
        return $actor->hasPermission(PermissionType::ManageContent);
    }

    public function update(Admin $actor, HomepageSection $section): bool
    {
        return $actor->hasPermission(PermissionType::ManageContent);
    }

    public function delete(Admin $actor, HomepageSection $section): bool
    {
        return $actor->hasPermission(PermissionType::ManageContent);
    }

    public function reorder(Admin $actor): bool
    {
        return $actor->hasPermission(PermissionType::ManageContent);
    }
}
