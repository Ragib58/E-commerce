<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Granular capabilities that can be granted to an admin role.
 *
 * Permissions are the authorization primitive: policies and middleware check
 * permissions, never role names. That indirection is what lets an operator
 * create a new role or adjust an existing one from the admin panel without a
 * code change — a check written as `can('update_products')` keeps working when
 * a new role is granted that permission, whereas `role === 'Product Manager'`
 * would not.
 *
 * The enum is the source of truth for which permissions *exist*; the database
 * stores which are *granted*. A permission removed from this enum is pruned
 * from the database by the seeder.
 */
enum PermissionType: string
{
    // Catalog — products
    case ViewProducts = 'view_products';
    case CreateProducts = 'create_products';
    case UpdateProducts = 'update_products';
    case DeleteProducts = 'delete_products';

    // Catalog — categories and brands
    case ViewCategories = 'view_categories';
    case ManageCategories = 'manage_categories';
    case ViewBrands = 'view_brands';
    case ManageBrands = 'manage_brands';

    // Orders
    case ViewOrders = 'view_orders';
    case UpdateOrders = 'update_orders';
    case CancelOrders = 'cancel_orders';
    case RefundOrders = 'refund_orders';

    // Payments
    case ViewPayments = 'view_payments';
    case ManagePayments = 'manage_payments';

    // Customers
    case ViewUsers = 'view_users';
    case ManageUsers = 'manage_users';

    // Staff administration
    case ViewAdmins = 'view_admins';
    case ManageAdmins = 'manage_admins';
    case ManageRoles = 'manage_roles';

    // Storefront content
    case ViewSettings = 'view_settings';
    case ManageSettings = 'manage_settings';
    case ManageContent = 'manage_content';
    case ManageMenus = 'manage_menus';
    case ManageBanners = 'manage_banners';

    // Reporting
    case ViewReports = 'view_reports';
    case ManageReports = 'manage_reports';

    // Support
    case ViewSupportTickets = 'view_support_tickets';
    case ManageSupportTickets = 'manage_support_tickets';

    public function label(): string
    {
        return match ($this) {
            self::ViewProducts => 'View products',
            self::CreateProducts => 'Create products',
            self::UpdateProducts => 'Update products',
            self::DeleteProducts => 'Delete products',
            self::ViewCategories => 'View categories',
            self::ManageCategories => 'Manage categories',
            self::ViewBrands => 'View brands',
            self::ManageBrands => 'Manage brands',
            self::ViewOrders => 'View orders',
            self::UpdateOrders => 'Update orders',
            self::CancelOrders => 'Cancel orders',
            self::RefundOrders => 'Refund orders',
            self::ViewPayments => 'View payments',
            self::ManagePayments => 'Manage payments',
            self::ViewUsers => 'View customers',
            self::ManageUsers => 'Manage customers',
            self::ViewAdmins => 'View administrators',
            self::ManageAdmins => 'Manage administrators',
            self::ManageRoles => 'Manage roles and permissions',
            self::ViewSettings => 'View settings',
            self::ManageSettings => 'Manage settings',
            self::ManageContent => 'Manage content',
            self::ManageMenus => 'Manage menus',
            self::ManageBanners => 'Manage banners',
            self::ViewReports => 'View reports',
            self::ManageReports => 'Manage reports',
            self::ViewSupportTickets => 'View support tickets',
            self::ManageSupportTickets => 'Manage support tickets',
        };
    }

    /**
     * Grouping used to lay out the permission matrix in the admin panel.
     */
    public function group(): string
    {
        return match ($this) {
            self::ViewProducts, self::CreateProducts, self::UpdateProducts, self::DeleteProducts,
            self::ViewCategories, self::ManageCategories, self::ViewBrands, self::ManageBrands => 'Catalog',

            self::ViewOrders, self::UpdateOrders, self::CancelOrders, self::RefundOrders => 'Orders',

            self::ViewPayments, self::ManagePayments => 'Payments',

            self::ViewUsers, self::ManageUsers => 'Customers',

            self::ViewAdmins, self::ManageAdmins, self::ManageRoles => 'Administration',

            self::ViewSettings, self::ManageSettings, self::ManageContent,
            self::ManageMenus, self::ManageBanners => 'Content',

            self::ViewReports, self::ManageReports => 'Reports',

            self::ViewSupportTickets, self::ManageSupportTickets => 'Support',
        };
    }

    /**
     * Permissions that grant control over the authorization system itself.
     *
     * These are restricted to Super Admin regardless of role configuration —
     * an admin who can edit roles can grant themselves anything, so granting
     * `manage_roles` is equivalent to granting every permission.
     */
    public function isPrivileged(): bool
    {
        return in_array($this, [self::ManageAdmins, self::ManageRoles], strict: true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, array<int, self>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::cases() as $permission) {
            $grouped[$permission->group()][] = $permission;
        }

        return $grouped;
    }
}
