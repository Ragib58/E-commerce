<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionType;
use App\Models\Admin;
use App\Models\Role;
use Illuminate\Auth\Access\Response;

/**
 * Authorization for role definitions themselves.
 *
 * Editing a role is more powerful than being assigned one: changing the
 * permission set of "Manager" instantly changes what every Manager can do. The
 * rank rule below stops an actor rewriting a role they could not otherwise
 * hold, which would otherwise be an indirect escalation path.
 */
final class RolePolicy
{
    public function viewAny(Admin $actor): bool
    {
        return $actor->hasAnyPermission([
            PermissionType::ManageRoles,
            PermissionType::ViewAdmins,
        ]);
    }

    public function view(Admin $actor, Role $role): bool
    {
        return $this->viewAny($actor);
    }

    public function create(Admin $actor): bool
    {
        return $actor->hasPermission(PermissionType::ManageRoles);
    }

    public function update(Admin $actor, Role $role): Response
    {
        if (! $actor->hasPermission(PermissionType::ManageRoles)) {
            return Response::deny('You do not have permission to manage roles.');
        }

        // Editing a role at or above your own rank would let you grant
        // yourself its permissions indirectly.
        if ($role->level >= $actor->roleLevel()) {
            return Response::deny('You cannot modify a role that ranks at or above your own.');
        }

        return Response::allow();
    }

    public function delete(Admin $actor, Role $role): Response
    {
        // Deleting "Super Admin" would permanently strand the installation
        // with no account able to restore access.
        if ($role->is_system) {
            return Response::deny('System roles cannot be deleted.');
        }

        if (! $actor->hasPermission(PermissionType::ManageRoles)) {
            return Response::deny('You do not have permission to manage roles.');
        }

        if ($role->level >= $actor->roleLevel()) {
            return Response::deny('You cannot delete a role that ranks at or above your own.');
        }

        // Deleting a role in use would silently strip permissions from
        // everyone holding it; require it to be vacated first so the
        // consequence is explicit.
        if ($role->admins()->exists()) {
            return Response::deny('This role is still assigned to one or more administrators.');
        }

        return Response::allow();
    }
}
