<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionType;
use App\Models\Admin;
use App\Models\Category;

/**
 * Authorization for the category taxonomy.
 *
 * Only two permissions exist here, unlike products: restructuring a taxonomy is
 * a single skill, and an operator trusted to create a category is trusted to
 * rename or move one. The write side is `manage_categories` throughout.
 */
final class CategoryPolicy
{
    public function viewAny(Admin $actor): bool
    {
        return $actor->hasAnyPermission([
            PermissionType::ViewCategories,
            PermissionType::ManageCategories,
            // A product editor must be able to read the category list to file
            // a product, without holding the right to restructure it.
            PermissionType::ViewProducts,
        ]);
    }

    public function view(Admin $actor, Category $category): bool
    {
        return $this->viewAny($actor);
    }

    public function create(Admin $actor): bool
    {
        return $actor->hasPermission(PermissionType::ManageCategories);
    }

    public function update(Admin $actor, Category $category): bool
    {
        return $actor->hasPermission(PermissionType::ManageCategories);
    }

    public function delete(Admin $actor, Category $category): bool
    {
        return $actor->hasPermission(PermissionType::ManageCategories);
    }

    public function reorder(Admin $actor): bool
    {
        return $actor->hasPermission(PermissionType::ManageCategories);
    }
}
