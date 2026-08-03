<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionType;
use App\Models\Admin;
use App\Models\Product;
use Illuminate\Auth\Access\Response;

/**
 * Authorization for catalog products.
 *
 * The catalog permissions are split four ways (view / create / update / delete)
 * because the roles genuinely differ: a merchandiser edits copy and pricing all
 * day, while deleting a product removes something with order history attached.
 * Collapsing them into one `manage_products` would force the person who writes
 * descriptions to hold the ability to withdraw inventory.
 */
final class ProductPolicy
{
    public function viewAny(Admin $actor): bool
    {
        return $actor->hasPermission(PermissionType::ViewProducts);
    }

    public function view(Admin $actor, Product $product): bool
    {
        return $actor->hasPermission(PermissionType::ViewProducts);
    }

    public function create(Admin $actor): bool
    {
        return $actor->hasPermission(PermissionType::CreateProducts);
    }

    public function update(Admin $actor, Product $product): bool
    {
        return $actor->hasPermission(PermissionType::UpdateProducts);
    }

    public function delete(Admin $actor, Product $product): Response
    {
        if (! $actor->hasPermission(PermissionType::DeleteProducts)) {
            return Response::deny('You do not have permission to delete products.');
        }

        return Response::allow();
    }

    public function restore(Admin $actor, Product $product): bool
    {
        return $actor->hasPermission(PermissionType::DeleteProducts);
    }

    /**
     * Adjusting stock is an update, not a catalog-authoring right.
     *
     * A warehouse account holds `update_products` to record counts without
     * being able to create or delete catalog entries.
     */
    public function adjustStock(Admin $actor, Product $product): bool
    {
        return $actor->hasPermission(PermissionType::UpdateProducts);
    }
}
