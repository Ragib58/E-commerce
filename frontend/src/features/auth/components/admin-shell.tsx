'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils/cn';
import { useAdminAuthStore } from '../stores/admin-auth-store';
import { useAdminLogout, useAdminSession } from '../hooks/use-admin-auth';
import type { PermissionName } from '../types';

/**
 * Admin panel chrome, with permission-filtered navigation.
 *
 * A nav item is rendered only if the account holds at least one of its
 * permissions. This is presentation, not enforcement: the API rejects an
 * unauthorized request regardless, so someone who forces a hidden link into
 * the address bar reaches a page that renders an access-denied notice and
 * whose data requests return 403.
 */

interface NavItem {
  label: string;
  href: string;
  /** Any one of these grants visibility. Omit for always-visible items. */
  permissions?: PermissionName[];
  /** Marks a section whose module lands in a later phase. */
  comingSoon?: boolean;
}

interface NavSection {
  title: string;
  items: NavItem[];
}

const NAV_SECTIONS: NavSection[] = [
  {
    title: 'Overview',
    items: [{ label: 'Dashboard', href: '/admin' }],
  },
  {
    title: 'Administration',
    items: [
      {
        label: 'Administrators',
        href: '/admin/administrators',
        permissions: ['view_admins', 'manage_admins'],
      },
      {
        label: 'Roles & Permissions',
        href: '/admin/roles',
        permissions: ['manage_roles'],
        comingSoon: true,
      },
    ],
  },
  {
    title: 'Catalog',
    items: [
      { label: 'Products', href: '/admin/products', permissions: ['view_products'] },
      {
        label: 'Categories',
        href: '/admin/categories',
        permissions: ['view_categories', 'manage_categories', 'view_products'],
      },
      {
        label: 'Brands',
        href: '/admin/brands',
        permissions: ['view_brands', 'manage_brands', 'view_products'],
      },
      { label: 'Inventory', href: '/admin/inventory', permissions: ['view_products'] },
    ],
  },
  {
    title: 'Commerce',
    items: [
      { label: 'Orders', href: '/admin/orders', permissions: ['view_orders'], comingSoon: true },
      { label: 'Payments', href: '/admin/payments', permissions: ['view_payments'], comingSoon: true },
      { label: 'Customers', href: '/admin/customers', permissions: ['view_users'], comingSoon: true },
    ],
  },
  {
    title: 'Content',
    items: [
      { label: 'Settings', href: '/admin/settings', permissions: ['view_settings'], comingSoon: true },
      { label: 'Menus', href: '/admin/menus', permissions: ['manage_menus'], comingSoon: true },
    ],
  },
];

export function AdminShell({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  const { admin } = useAdminSession();
  const logout = useAdminLogout();

  const canAny = useAdminAuthStore((state) => state.canAny);

  const visibleSections = NAV_SECTIONS.map((section) => ({
    ...section,
    items: section.items.filter(
      (item) => item.permissions === undefined || canAny(item.permissions),
    ),
  })).filter((section) => section.items.length > 0);

  return (
    <div className="flex min-h-screen bg-muted/20">
      <aside className="hidden w-64 shrink-0 border-r border-border bg-card lg:block">
        <div className="border-b border-border px-5 py-4">
          <p className="text-sm font-semibold">Admin Panel</p>
          {admin ? (
            <p className="mt-0.5 truncate text-xs text-muted-foreground">{admin.email}</p>
          ) : null}
        </div>

        <nav className="space-y-5 px-3 py-4" aria-label="Admin navigation">
          {visibleSections.map((section) => (
            <div key={section.title}>
              <p className="px-2 pb-1.5 text-[0.68rem] font-semibold uppercase tracking-wide text-muted-foreground">
                {section.title}
              </p>

              <ul className="space-y-0.5">
                {section.items.map((item) => (
                  <li key={item.href}>
                    {item.comingSoon ? (
                      <span
                        className="block cursor-not-allowed rounded-md px-2.5 py-1.5 text-sm text-muted-foreground/60"
                        title="Available in a later phase"
                      >
                        {item.label}
                      </span>
                    ) : (
                      <Link
                        href={item.href}
                        aria-current={pathname === item.href ? 'page' : undefined}
                        className={cn(
                          'block rounded-md px-2.5 py-1.5 text-sm transition-colors',
                          pathname === item.href
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-muted',
                        )}
                      >
                        {item.label}
                      </Link>
                    )}
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </nav>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex items-center justify-between gap-4 border-b border-border bg-card px-5 py-3">
          <div className="min-w-0">
            {admin ? (
              <>
                <p className="truncate text-sm font-medium">{admin.name}</p>
                <p className="truncate text-xs text-muted-foreground">
                  {admin.roles.map((role) => role.label).join(', ') || 'No role assigned'}
                </p>
              </>
            ) : null}
          </div>

          <div className="flex items-center gap-3">
            <Link
              href="/admin/change-password"
              className="text-xs text-muted-foreground hover:text-foreground"
            >
              Change password
            </Link>

            <button
              type="button"
              onClick={() => logout.mutate()}
              disabled={logout.isPending}
              className="rounded-md border border-border px-3 py-1.5 text-xs font-medium transition-colors hover:bg-muted disabled:opacity-60"
            >
              {logout.isPending ? 'Signing out…' : 'Sign out'}
            </button>
          </div>
        </header>

        <main className="min-w-0 flex-1 p-5 lg:p-8">{children}</main>
      </div>
    </div>
  );
}
