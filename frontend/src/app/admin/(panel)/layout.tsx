'use client';

import type { ReactNode } from 'react';
import { AdminGuard } from '@/features/auth/components/admin-guard';
import { AdminShell } from '@/features/auth/components/admin-shell';

/**
 * Layout for authenticated admin pages.
 *
 * The `(panel)` route group exists so this guard wraps the panel without also
 * wrapping /admin/login, /admin/forgot-password, /admin/reset-password, or
 * /admin/change-password — each of which must stay reachable to someone who is
 * signed out or locked behind a forced rotation.
 */
export default function AdminPanelLayout({ children }: { children: ReactNode }) {
  return (
    <AdminGuard>
      <AdminShell>{children}</AdminShell>
    </AdminGuard>
  );
}
