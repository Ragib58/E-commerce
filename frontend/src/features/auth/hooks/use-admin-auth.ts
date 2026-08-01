'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import { useEffect } from 'react';
import { ApiError } from '@/lib/api/errors';
import { queryKeys } from '@/config/query-keys';
import * as authApi from '../api';
import { useAdminAuthStore } from '../stores/admin-auth-store';
import type { PermissionName, RoleName } from '../types';
import type { LoginInput } from '../schemas';

export function useAdminSession() {
  const { token, admin, permissions, mustChangePassword, isHydrated } = useAdminAuthStore();

  return {
    admin,
    token,
    permissions,
    mustChangePassword,
    isHydrated,
    isAuthenticated: token !== null,
  };
}

/**
 * Permission check for conditional rendering.
 *
 * Hides UI the account cannot use. The API enforces the same rule
 * independently, so this is about not showing dead buttons — not about
 * security.
 */
export function useCan(permission: PermissionName): boolean {
  return useAdminAuthStore((state) => state.can(permission));
}

export function useCanAny(permissions: PermissionName[]): boolean {
  return useAdminAuthStore((state) => state.canAny(permissions));
}

export function useCanAll(permissions: PermissionName[]): boolean {
  return useAdminAuthStore((state) => state.canAll(permissions));
}

export function useHasRole(role: RoleName): boolean {
  return useAdminAuthStore((state) => state.hasRole(role));
}

/**
 * Revalidate the staff session and refresh the permission set.
 *
 * Running on every panel load is what makes a role change take effect on
 * refresh instead of requiring a sign-out. A 401 or a deactivation (403)
 * clears the store immediately.
 */
export function useCurrentAdmin() {
  const { token, isHydrated } = useAdminAuthStore();
  const setAdmin = useAdminAuthStore((state) => state.setAdmin);
  const clear = useAdminAuthStore((state) => state.clear);

  const query = useQuery({
    queryKey: queryKeys.auth.admin(),
    queryFn: authApi.fetchCurrentAdmin,
    enabled: isHydrated && token !== null,
    retry: (failureCount, error) =>
      !(error instanceof ApiError && (error.isUnauthenticated || error.isForbidden)) &&
      failureCount < 1,
    staleTime: 60_000,
  });

  useEffect(() => {
    if (query.data) {
      setAdmin(query.data);
    }
  }, [query.data, setAdmin]);

  useEffect(() => {
    if (!(query.error instanceof ApiError)) {
      return;
    }

    // ACCOUNT_DEACTIVATED arrives as a 403; treating it as a session end
    // rather than a permission error is what makes deactivation feel
    // immediate to the affected admin.
    if (query.error.isUnauthenticated || query.error.code === 'ACCOUNT_DEACTIVATED') {
      clear();
    }
  }, [query.error, clear]);

  return query;
}

export function useAdminLogin() {
  const setSession = useAdminAuthStore((state) => state.setSession);
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: LoginInput) => authApi.adminLogin(input),
    onSuccess: (session) => {
      setSession(session.token, session.admin, session.must_change_password);
      void queryClient.invalidateQueries();
    },
  });
}

export function useAdminLogout() {
  const clear = useAdminAuthStore((state) => state.clear);
  const queryClient = useQueryClient();
  const router = useRouter();

  return useMutation({
    mutationFn: () => authApi.adminLogout(),
    onSettled: () => {
      clear();
      queryClient.clear();
      router.push('/admin/login');
    },
  });
}

export function useAdminChangePassword() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: authApi.adminChangePassword,
    onSuccess: () => {
      // Clears the must_change_password flag by refetching /me, which is what
      // releases the account from the forced-rotation gate.
      void queryClient.invalidateQueries({ queryKey: queryKeys.auth.admin() });
    },
  });
}

export function useAdminForgotPassword() {
  return useMutation({ mutationFn: authApi.adminForgotPassword });
}

export function useAdminResetPassword() {
  return useMutation({ mutationFn: authApi.adminResetPassword });
}
