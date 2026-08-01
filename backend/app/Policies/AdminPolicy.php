<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionType;
use App\Enums\RoleType;
use App\Models\Admin;
use Illuminate\Auth\Access\Response;

/**
 * Authorization for staff account management.
 *
 * The rank rules here are what stop `manage_admins` from being a blank cheque.
 * Without them, any admin holding that permission could delete the Super Admin
 * and take over the installation — so holding the permission is necessary but
 * never sufficient.
 *
 * Super Admin bypasses all of this via the Gate::before hook in
 * AuthServiceProvider, so these methods only ever run for lower ranks.
 */
final class AdminPolicy
{
    public function viewAny(Admin $actor): bool
    {
        return $actor->hasPermission(PermissionType::ViewAdmins);
    }

    public function view(Admin $actor, Admin $target): bool
    {
        // Anyone may read their own record, regardless of permissions.
        if ($actor->is($target)) {
            return true;
        }

        return $actor->hasPermission(PermissionType::ViewAdmins);
    }

    public function create(Admin $actor): bool
    {
        return $actor->hasPermission(PermissionType::ManageAdmins);
    }

    public function update(Admin $actor, Admin $target): Response
    {
        if ($actor->is($target)) {
            return Response::allow();
        }

        if (! $actor->hasPermission(PermissionType::ManageAdmins)) {
            return Response::deny('You do not have permission to manage administrators.');
        }

        // Strict rank: equal-level admins cannot edit each other.
        if (! $actor->outranks($target)) {
            return Response::deny('You cannot modify an administrator of equal or higher rank.');
        }

        return Response::allow();
    }

    /**
     * `delete` and `activate` are exempt from the Super Admin Gate::before
     * bypass (see AuthServiceProvider), so these methods run for every actor
     * including Super Admins. The self-protection rule is checked first and
     * binds everyone; the permission and rank checks then skip Super Admins,
     * who would otherwise have been waved through by the bypass.
     */
    public function delete(Admin $actor, Admin $target): Response
    {
        // Binds Super Admins too: deleting your own account is the fastest way
        // to lock yourself out, and it is never what was intended.
        if ($actor->is($target)) {
            return Response::deny('You cannot delete your own account.');
        }

        if ($actor->isSuperAdmin()) {
            return Response::allow();
        }

        if (! $actor->hasPermission(PermissionType::ManageAdmins)) {
            return Response::deny('You do not have permission to manage administrators.');
        }

        if (! $actor->outranks($target)) {
            return Response::deny('You cannot delete an administrator of equal or higher rank.');
        }

        return Response::allow();
    }

    public function activate(Admin $actor, Admin $target): Response
    {
        if ($actor->is($target)) {
            return Response::deny('You cannot change your own account status.');
        }

        if ($actor->isSuperAdmin()) {
            return Response::allow();
        }

        return $this->update($actor, $target);
    }

    /**
     * Assigning roles is the escalation-sensitive operation.
     *
     * The permission and the rank check are enforced here; the service
     * additionally verifies that no *individual* role being assigned outranks
     * the actor, which a policy cannot see because it does not receive the
     * requested role list.
     */
    public function assignRoles(Admin $actor, Admin $target): Response
    {
        if ($actor->is($target)) {
            return Response::deny('You cannot change your own roles.');
        }

        if (! $actor->hasPermission(PermissionType::ManageRoles)) {
            return Response::deny('You do not have permission to assign roles.');
        }

        if (! $actor->outranks($target)) {
            return Response::deny('You cannot change the roles of an administrator of equal or higher rank.');
        }

        return Response::allow();
    }

    public function assignPermissions(Admin $actor, Admin $target): Response
    {
        return $this->assignRoles($actor, $target);
    }

    /**
     * Only Super Admin may create another Super Admin.
     *
     * Checked explicitly rather than relying on rank alone: rank comparison
     * uses strict inequality, so nothing below Super Admin could pass anyway —
     * but stating it makes the intent unmissable to the next reader.
     */
    public function grantSuperAdmin(Admin $actor): bool
    {
        return $actor->hasRole(RoleType::SuperAdmin);
    }
}
