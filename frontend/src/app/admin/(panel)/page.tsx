'use client';

import { useAdminSession } from '@/features/auth/hooks/use-admin-auth';
import { Can } from '@/features/auth/components/admin-guard';

/**
 * Admin dashboard.
 *
 * Shows the signed-in account's roles and effective permissions, which makes
 * the RBAC configuration inspectable at a glance — useful when diagnosing "why
 * can't this person see that section?".
 */
export default function AdminDashboardPage() {
  const { admin, permissions } = useAdminSession();

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold tracking-tight">Dashboard</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Authentication and access control phase. Catalog, orders, and payments follow.
        </p>
      </header>

      <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div className="rounded-lg border border-border bg-card p-4">
          <p className="text-xs uppercase tracking-wide text-muted-foreground">Signed in as</p>
          <p className="mt-1 truncate font-medium">{admin?.name ?? '—'}</p>
          <p className="truncate text-sm text-muted-foreground">{admin?.email ?? '—'}</p>
        </div>

        <div className="rounded-lg border border-border bg-card p-4">
          <p className="text-xs uppercase tracking-wide text-muted-foreground">Roles</p>
          <p className="mt-1 font-medium">
            {admin?.roles.map((role) => role.label).join(', ') || 'None'}
          </p>
          {admin?.role_level !== undefined ? (
            <p className="text-sm text-muted-foreground">Rank level {admin.role_level}</p>
          ) : null}
        </div>

        <div className="rounded-lg border border-border bg-card p-4">
          <p className="text-xs uppercase tracking-wide text-muted-foreground">Permissions</p>
          <p className="mt-1 text-2xl font-semibold">
            {admin?.is_super_admin ? 'All' : permissions.length}
          </p>
        </div>
      </section>

      {/* Demonstrates the Can component: this block is absent entirely for an
          account without administrator-management permissions. */}
      <Can
        permissions={['view_admins', 'manage_admins']}
        fallback={
          <div className="rounded-lg border border-border bg-card p-4 text-sm text-muted-foreground">
            Your role does not include administrator management.
          </div>
        }
      >
        <section className="rounded-lg border border-border bg-card p-4">
          <h2 className="text-sm font-semibold">Administrator management</h2>
          <p className="mt-1 text-sm text-muted-foreground">
            You can view and manage staff accounts from the Administrators section.
          </p>
        </section>
      </Can>

      <section className="rounded-lg border border-border bg-card p-4">
        <h2 className="text-sm font-semibold">Effective permissions</h2>

        {admin?.is_super_admin ? (
          <p className="mt-2 text-sm text-muted-foreground">
            Super Admin bypasses permission checks and holds every capability.
          </p>
        ) : permissions.length === 0 ? (
          <p className="mt-2 text-sm text-muted-foreground">No permissions granted.</p>
        ) : (
          <ul className="mt-3 flex flex-wrap gap-1.5">
            {permissions.map((permission) => (
              <li
                key={permission}
                className="rounded-full bg-muted px-2.5 py-0.5 font-mono text-xs text-muted-foreground"
              >
                {permission}
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
