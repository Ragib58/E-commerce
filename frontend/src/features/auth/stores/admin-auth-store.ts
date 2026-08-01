'use client';

import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';
import { registerTokenResolver } from '@/lib/api/auth-token';
import type { AdminUser, PermissionName, RoleName } from '../types';

/**
 * Staff session state, including the effective permission set.
 *
 * The permission list here drives which navigation items and actions render.
 * That is a usability measure, not a security boundary — the API enforces
 * every permission independently, so a user who edits this state in devtools
 * gains a menu item that returns 403 when clicked, and nothing more.
 */

interface AdminAuthState {
  token: string | null;
  admin: AdminUser | null;
  permissions: PermissionName[];
  mustChangePassword: boolean;
  isHydrated: boolean;

  setSession: (token: string, admin: AdminUser, mustChangePassword: boolean) => void;
  setAdmin: (admin: AdminUser) => void;
  clear: () => void;
  markHydrated: () => void;

  can: (permission: PermissionName) => boolean;
  canAny: (permissions: PermissionName[]) => boolean;
  canAll: (permissions: PermissionName[]) => boolean;
  hasRole: (role: RoleName) => boolean;
  isSuperAdmin: () => boolean;
}

export const useAdminAuthStore = create<AdminAuthState>()(
  persist(
    (set, get) => ({
      token: null,
      admin: null,
      permissions: [],
      mustChangePassword: false,
      isHydrated: false,

      setSession: (token, admin, mustChangePassword) =>
        set({
          token,
          admin,
          permissions: (admin.permissions ?? []) as PermissionName[],
          mustChangePassword,
        }),

      setAdmin: (admin) =>
        set({
          admin,
          // Refreshed from every /me response, so a role change applied by
          // another administrator takes effect on the next page load.
          permissions: (admin.permissions ?? []) as PermissionName[],
          mustChangePassword: admin.must_change_password,
        }),

      clear: () =>
        set({ token: null, admin: null, permissions: [], mustChangePassword: false }),

      markHydrated: () => set({ isHydrated: true }),

      can: (permission) => {
        const state = get();

        // Mirrors the server's Gate::before bypass, so the UI shows a Super
        // Admin everything without the API having to enumerate permissions.
        if (state.admin?.is_super_admin === true) {
          return true;
        }

        return state.permissions.includes(permission);
      },

      canAny: (permissions) => permissions.some((permission) => get().can(permission)),
      canAll: (permissions) => permissions.every((permission) => get().can(permission)),

      hasRole: (role) => get().admin?.roles.some((candidate) => candidate.name === role) ?? false,

      isSuperAdmin: () => get().admin?.is_super_admin === true,
    }),
    {
      name: 'admin-auth',
      storage: createJSONStorage(() => sessionStorage),
      partialize: (state) => ({
        token: state.token,
        admin: state.admin,
        permissions: state.permissions,
        mustChangePassword: state.mustChangePassword,
      }),
      onRehydrateStorage: () => (state) => {
        state?.markHydrated();
      },
    },
  ),
);

registerTokenResolver('admin', () => useAdminAuthStore.getState().token);

export function getAdminToken(): string | null {
  return useAdminAuthStore.getState().token;
}
