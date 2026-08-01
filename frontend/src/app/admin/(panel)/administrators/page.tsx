'use client';

import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/api/client';
import { queryKeys } from '@/config/query-keys';
import { AdminGuard, Can } from '@/features/auth/components/admin-guard';
import { adminSchema, type AdminUser } from '@/features/auth/types';
import { z } from 'zod';

/**
 * Staff account list.
 *
 * Wrapped in a second AdminGuard with explicit permissions: the panel layout
 * only proves the visitor is an authenticated admin, whereas this page also
 * requires the right to view staff accounts. Someone who types the URL
 * directly gets the access-denied notice instead of an empty table plus a 403
 * in the console.
 */
export default function AdministratorsPage() {
  return (
    <AdminGuard requiredPermissions={['view_admins', 'manage_admins']}>
      <AdministratorList />
    </AdminGuard>
  );
}

function AdministratorList() {
  const { data, isPending, isError, error } = useQuery({
    queryKey: queryKeys.admins.list(),
    queryFn: async () => {
      const result = await apiClient.get<unknown>('/admin/admins', { cache: 'no-store' });

      return z.array(adminSchema).parse(result.data);
    },
  });

  return (
    <div className="space-y-6">
      <header className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">Administrators</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            Staff accounts and their assigned roles.
          </p>
        </div>

        {/* Creating requires a strictly higher permission than viewing, so the
            button is hidden for read-only accounts. */}
        <Can permission="manage_admins">
          <button
            type="button"
            className="rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90"
          >
            New administrator
          </button>
        </Can>
      </header>

      {isPending ? (
        <p className="text-sm text-muted-foreground">Loading administrators…</p>
      ) : isError ? (
        <div
          role="alert"
          className="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2.5 text-sm text-destructive"
        >
          {error instanceof Error ? error.message : 'Unable to load administrators.'}
        </div>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-border bg-card">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border text-left">
                <th className="px-4 py-2.5 text-xs uppercase tracking-wide text-muted-foreground">
                  Name
                </th>
                <th className="px-4 py-2.5 text-xs uppercase tracking-wide text-muted-foreground">
                  Email
                </th>
                <th className="px-4 py-2.5 text-xs uppercase tracking-wide text-muted-foreground">
                  Roles
                </th>
                <th className="px-4 py-2.5 text-xs uppercase tracking-wide text-muted-foreground">
                  Status
                </th>
              </tr>
            </thead>

            <tbody>
              {data.map((admin: AdminUser) => (
                <tr key={admin.id} className="border-b border-border last:border-0">
                  <td className="px-4 py-2.5 font-medium">{admin.name}</td>
                  <td className="px-4 py-2.5 text-muted-foreground">{admin.email}</td>
                  <td className="px-4 py-2.5 text-muted-foreground">
                    {admin.roles.map((role) => role.label).join(', ') || '—'}
                  </td>
                  <td className="px-4 py-2.5">
                    <span
                      className={
                        admin.is_active
                          ? 'rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-600 dark:text-emerald-400'
                          : 'rounded-full bg-red-500/10 px-2 py-0.5 text-xs font-medium text-red-600 dark:text-red-400'
                      }
                    >
                      {admin.is_active ? 'Active' : 'Inactive'}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
