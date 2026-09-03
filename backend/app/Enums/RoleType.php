<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * System-defined admin roles.
 *
 * These seven are seeded and flagged `is_system`, which prevents the admin
 * panel deleting them — an operator who deleted "Super Admin" would lock
 * everyone out of the authorization system permanently. Operators may still
 * create additional custom roles, and may edit the permission sets of
 * non-system roles freely.
 *
 * The default permission map below is applied at seed time only. Once seeded,
 * the database is authoritative: re-seeding never silently reverts an
 * operator's deliberate permission changes.
 */
enum RoleType: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Manager = 'manager';
    case OrderManager = 'order_manager';
    case ProductManager = 'product_manager';
    case ContentManager = 'content_manager';
    case SupportStaff = 'support_staff';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::Manager => 'Manager',
            self::OrderManager => 'Order Manager',
            self::ProductManager => 'Product Manager',
            self::ContentManager => 'Content Manager',
            self::SupportStaff => 'Support Staff',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Unrestricted access, including administrator and role management.',
            self::Admin => 'Broad operational access excluding role and administrator management.',
            self::Manager => 'Oversees catalog, orders, and reporting.',
            self::OrderManager => 'Handles order fulfilment, cancellations, and refunds.',
            self::ProductManager => 'Maintains the product catalog, categories, and brands.',
            self::ContentManager => 'Maintains storefront content, menus, banners, and settings.',
            self::SupportStaff => 'Handles customer enquiries with read-only operational access.',
        };
    }

    /**
     * Hierarchy level. Higher outranks lower.
     *
     * Used to stop an admin editing, deleting, or deactivating a peer or
     * superior — without it, any admin holding `manage_admins` could delete
     * the Super Admin and seize control.
     */
    public function level(): int
    {
        return match ($this) {
            self::SuperAdmin => 100,
            self::Admin => 80,
            self::Manager => 60,
            self::OrderManager, self::ProductManager, self::ContentManager => 40,
            self::SupportStaff => 20,
        };
    }

    /**
     * Whether this role implicitly holds every permission.
     *
     * Super Admin bypasses permission checks entirely (see the Gate::before
     * hook in AuthServiceProvider). Modelling it as a bypass rather than as a
     * role seeded with all permissions means a newly added permission is
     * immediately available to Super Admin without a re-seed — no window
     * exists where the top role silently lacks a new capability.
     */
    public function hasImplicitAllAccess(): bool
    {
        return $this === self::SuperAdmin;
    }

    /**
     * Permissions granted when this role is first seeded.
     *
     * @return array<int, PermissionType>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            // Bypasses checks entirely; an explicit list would be redundant
            // and would drift as new permissions are added.
            self::SuperAdmin => [],

            self::Admin => array_values(array_filter(
                PermissionType::cases(),
                // Excluded deliberately: manage_admins and manage_roles are
                // escalation vectors — granting them is equivalent to
                // granting everything, so they stay with Super Admin.
                static fn (PermissionType $permission): bool => ! $permission->isPrivileged(),
            )),

            self::Manager => [
                PermissionType::ViewProducts,
                PermissionType::CreateProducts,
                PermissionType::UpdateProducts,
                PermissionType::ViewCategories,
                PermissionType::ManageCategories,
                PermissionType::ViewBrands,
                PermissionType::ManageBrands,
                PermissionType::ViewOrders,
                PermissionType::UpdateOrders,
                PermissionType::CancelOrders,
                PermissionType::ViewPayments,
                PermissionType::ViewShipping,
                PermissionType::ManageShipping,
                PermissionType::ViewCoupons,
                PermissionType::ManageCoupons,
                PermissionType::ViewUsers,
                PermissionType::ViewReports,
                PermissionType::ManageReports,
                PermissionType::ViewSettings,
                PermissionType::ViewSupportTickets,
            ],

            self::OrderManager => [
                PermissionType::ViewOrders,
                PermissionType::UpdateOrders,
                PermissionType::CancelOrders,
                PermissionType::RefundOrders,
                PermissionType::ViewPayments,
                PermissionType::ViewShipping,
                PermissionType::ViewCoupons,
                PermissionType::ViewUsers,
                PermissionType::ViewProducts,
                PermissionType::ViewReports,
            ],

            self::ProductManager => [
                PermissionType::ViewProducts,
                PermissionType::CreateProducts,
                PermissionType::UpdateProducts,
                PermissionType::DeleteProducts,
                PermissionType::ViewCategories,
                PermissionType::ManageCategories,
                PermissionType::ViewBrands,
                PermissionType::ManageBrands,
                PermissionType::ViewReports,
            ],

            self::ContentManager => [
                PermissionType::ViewSettings,
                PermissionType::ManageSettings,
                PermissionType::ManageContent,
                PermissionType::ManageMenus,
                PermissionType::ManageBanners,
                PermissionType::ViewProducts,
                PermissionType::ViewCategories,
                PermissionType::ViewBrands,
            ],

            self::SupportStaff => [
                PermissionType::ViewOrders,
                PermissionType::ViewUsers,
                PermissionType::ViewProducts,
                PermissionType::ViewPayments,
                PermissionType::ViewSupportTickets,
                PermissionType::ManageSupportTickets,
            ],
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
