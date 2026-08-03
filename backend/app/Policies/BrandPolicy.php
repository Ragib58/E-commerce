<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionType;
use App\Models\Admin;
use App\Models\Brand;

/**
 * Authorization for brands.
 */
final class BrandPolicy
{
    public function viewAny(Admin $actor): bool
    {
        return $actor->hasAnyPermission([
            PermissionType::ViewBrands,
            PermissionType::ManageBrands,
            // Needed to populate the brand selector on the product form.
            PermissionType::ViewProducts,
        ]);
    }

    public function view(Admin $actor, Brand $brand): bool
    {
        return $this->viewAny($actor);
    }

    public function create(Admin $actor): bool
    {
        return $actor->hasPermission(PermissionType::ManageBrands);
    }

    public function update(Admin $actor, Brand $brand): bool
    {
        return $actor->hasPermission(PermissionType::ManageBrands);
    }

    public function delete(Admin $actor, Brand $brand): bool
    {
        return $actor->hasPermission(PermissionType::ManageBrands);
    }
}
