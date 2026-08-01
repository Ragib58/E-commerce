import { z } from 'zod';

/**
 * Response shapes for the authentication endpoints.
 *
 * Parsed at the boundary rather than trusted: the API is a separate deployable
 * that can change independently, and a silently-missing `permissions` array
 * would surface as an admin panel with no navigation, far from its cause.
 */

export const customerSchema = z.object({
  id: z.string(),
  name: z.string(),
  email: z.string(),
  phone: z.string().nullish(),
  avatar_url: z.string().nullish(),
  date_of_birth: z.string().nullish(),
  email_verified: z.boolean(),
  email_verified_at: z.string().nullish(),
  is_active: z.boolean(),
  last_login_at: z.string().nullish(),
  created_at: z.string().nullish(),
});

export const roleSchema = z.object({
  name: z.string(),
  label: z.string(),
  description: z.string().nullish(),
  level: z.number(),
  is_system: z.boolean(),
});

export const adminSchema = z.object({
  id: z.string(),
  name: z.string(),
  email: z.string(),
  phone: z.string().nullish(),
  avatar_url: z.string().nullish(),
  is_active: z.boolean(),
  must_change_password: z.boolean(),
  last_login_at: z.string().nullish(),
  created_at: z.string().nullish(),
  roles: z.array(roleSchema).default([]),
  role_level: z.number().optional(),
  is_super_admin: z.boolean().optional(),
  // Present on /me and login; absent when an admin appears inside a list.
  permissions: z.array(z.string()).optional(),
});

export const customerSessionSchema = z.object({
  user: customerSchema,
  token: z.string(),
  token_type: z.string(),
  expires_at: z.string().nullish(),
});

export const adminSessionSchema = z.object({
  admin: adminSchema,
  token: z.string(),
  token_type: z.string(),
  expires_at: z.string().nullish(),
  must_change_password: z.boolean(),
});

export type Customer = z.infer<typeof customerSchema>;
export type Role = z.infer<typeof roleSchema>;
export type AdminUser = z.infer<typeof adminSchema>;
export type CustomerSession = z.infer<typeof customerSessionSchema>;
export type AdminSession = z.infer<typeof adminSessionSchema>;

/**
 * Permission names, mirroring App\Enums\PermissionType.
 *
 * A union type rather than `string` so a typo in `useCan('mange_orders')` is a
 * compile error instead of a menu item that never appears.
 */
export type PermissionName =
  | 'view_products'
  | 'create_products'
  | 'update_products'
  | 'delete_products'
  | 'view_categories'
  | 'manage_categories'
  | 'view_brands'
  | 'manage_brands'
  | 'view_orders'
  | 'update_orders'
  | 'cancel_orders'
  | 'refund_orders'
  | 'view_payments'
  | 'manage_payments'
  | 'view_users'
  | 'manage_users'
  | 'view_admins'
  | 'manage_admins'
  | 'manage_roles'
  | 'view_settings'
  | 'manage_settings'
  | 'manage_content'
  | 'manage_menus'
  | 'manage_banners'
  | 'view_reports'
  | 'manage_reports'
  | 'view_support_tickets'
  | 'manage_support_tickets';

/** Role names, mirroring App\Enums\RoleType. */
export type RoleName =
  | 'super_admin'
  | 'admin'
  | 'manager'
  | 'order_manager'
  | 'product_manager'
  | 'content_manager'
  | 'support_staff';
