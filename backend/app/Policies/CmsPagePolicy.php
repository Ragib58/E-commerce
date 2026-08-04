<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionType;
use App\Models\Admin;
use App\Models\CmsPage;

/**
 * Authorization for editorial pages and the homepage builder.
 *
 * One write permission, `manage_content`: composing a homepage and writing a
 * refund policy are the same job in every store small enough to have one
 * content editor, and splitting them would produce a permission nobody grants
 * separately.
 *
 * Reads also admit `view_settings`, so a staff member who can inspect store
 * configuration can read the pages that configuration links to.
 */
final class CmsPagePolicy
{
    public function viewAny(Admin $actor): bool
    {
        return $actor->hasAnyPermission([
            PermissionType::ManageContent,
            PermissionType::ViewSettings,
            PermissionType::ManageSettings,
        ]);
    }

    public function view(Admin $actor, CmsPage $page): bool
    {
        return $this->viewAny($actor);
    }

    public function create(Admin $actor): bool
    {
        return $actor->hasPermission(PermissionType::ManageContent);
    }

    public function update(Admin $actor, CmsPage $page): bool
    {
        return $actor->hasPermission(PermissionType::ManageContent);
    }

    /**
     * System pages are refused here as well as in the service.
     *
     * The service throws a validation error explaining what to do instead,
     * which is the better message; this is the belt to that braces, so a future
     * caller that bypasses the service cannot delete a legally required page.
     */
    public function delete(Admin $actor, CmsPage $page): bool
    {
        return $actor->hasPermission(PermissionType::ManageContent) && ! $page->is_system;
    }

    public function reorder(Admin $actor): bool
    {
        return $actor->hasPermission(PermissionType::ManageContent);
    }
}
