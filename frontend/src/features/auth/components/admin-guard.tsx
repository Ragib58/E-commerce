'use client';

import { useRouter, usePathname } from 'next/navigation';
import { useEffect, type ReactNode } from 'react';
import { useAdminSession, useCurrentAdmin } from '../hooks/use-admin-auth';
import type { PermissionName } from '../types';
import { useAdminAuthStore } from '../stores/admin-auth-store';

/**
 * Protects the admin panel.
 *
 * This is a usability boundary, not a security one: it prevents an
 * unauthenticated user seeing a broken shell, and stops an admin clicking into
 * a section that would only return 403. Every endpoint behind it enforces the
 * same rules server-side, so bypassing this guard in devtools yields an empty
 * page and a string of 403s.
 *
 * Rendering order matters here. The guard must not redirect before the store
 * has rehydrated from sessionStorage, or a page refresh would bounce a
 * legitimately signed-in admin to the login screen.
 */
export function AdminGuard({
  children,
  requiredPermissions,
  requireAll = false,
}: {
  children: ReactNode;
  /** Omit to require authentication only. */
  requiredPermissions?: PermissionName[];
  /** True to require every listed permission rather than any one of them. */
  requireAll?: boolean;
}) {
  const router = useRouter();
  const pathname = usePathname();
  const { isAuthenticated, isHydrated, mustChangePassword } = useAdminSession();

  // Revalidates the session and refreshes permissions on mount.
  const { isPending, isError } = useCurrentAdmin();

  const canAccess = useAdminAuthStore((state) => {
    if (!requiredPermissions || requiredPermissions.length === 0) {
      return true;
    }

    return requireAll ? state.canAll(requiredPermissions) : state.canAny(requiredPermissions);
  });

  useEffect(() => {
    if (!isHydrated) {
      return;
    }

    if (!isAuthenticated) {
      // Preserve the intended destination so the user lands where they meant
      // to go after signing in.
      const next = encodeURIComponent(pathname);
      router.replace(`/admin/login?next=${next}`);

      return;
    }

    // The API 403s every other endpoint until this is satisfied, so sending
    // the user anywhere else would show them a wall of errors.
    if (mustChangePassword && pathname !== '/admin/change-password') {
      router.replace('/admin/change-password');
    }
  }, [isHydrated, isAuthenticated, mustChangePassword, pathname, router]);

  if (!isHydrated || (isAuthenticated && isPending)) {
    return <GuardFallback message="Checking your session…" />;
  }

  if (!isAuthenticated || isError) {
    return <GuardFallback message="Redirecting to sign in…" />;
  }

  if (!canAccess) {
    return (
      <div className="mx-auto max-w-lg px-4 py-24 text-center">
        <p className="text-sm font-medium text-destructive">403</p>
        <h1 className="mt-2 text-2xl font-semibold tracking-tight">Access denied</h1>
        <p className="mt-3 text-muted-foreground">
          Your role does not include permission to view this section. Contact a system
          administrator if you believe this is a mistake.
        </p>
      </div>
    );
  }

  return <>{children}</>;
}

function GuardFallback({ message }: { message: string }) {
  return (
    <div
      className="flex min-h-[50vh] items-center justify-center px-4"
      role="status"
      aria-live="polite"
    >
      <p className="text-sm text-muted-foreground">{message}</p>
    </div>
  );
}

/**
 * Conditionally render children based on a permission.
 *
 * Used for individual buttons and menu items inside an already-guarded page.
 */
export function Can({
  permission,
  permissions,
  requireAll = false,
  fallback = null,
  children,
}: {
  permission?: PermissionName;
  permissions?: PermissionName[];
  requireAll?: boolean;
  fallback?: ReactNode;
  children: ReactNode;
}) {
  const allowed = useAdminAuthStore((state) => {
    const required = permission ? [permission] : (permissions ?? []);

    if (required.length === 0) {
      return true;
    }

    return requireAll ? state.canAll(required) : state.canAny(required);
  });

  return <>{allowed ? children : fallback}</>;
}
