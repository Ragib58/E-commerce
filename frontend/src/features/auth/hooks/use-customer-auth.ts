'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import { useEffect } from 'react';
import { ApiError } from '@/lib/api/errors';
import { queryKeys } from '@/config/query-keys';
import * as authApi from '../api';
import { useCustomerAuthStore } from '../stores/customer-auth-store';
import type { LoginInput, RegisterInput } from '../schemas';

/**
 * Customer session hooks.
 *
 * Mutations own the store writes, so a component never has to remember to
 * persist the token after a successful login.
 */

export function useCustomerSession() {
  const { token, user, isHydrated } = useCustomerAuthStore();

  return {
    user,
    token,
    isHydrated,
    isAuthenticated: token !== null,
    isVerified: user?.email_verified === true,
  };
}

/**
 * Revalidate the session against the API.
 *
 * The persisted snapshot is only a first-paint optimisation; this is what
 * makes the client's view of the account authoritative. A 401 clears the
 * store, so a token revoked elsewhere (a password change on another device)
 * signs this tab out on its next load.
 */
export function useCurrentCustomer() {
  const { token, isHydrated } = useCustomerAuthStore();
  const setUser = useCustomerAuthStore((state) => state.setUser);
  const clear = useCustomerAuthStore((state) => state.clear);

  const query = useQuery({
    queryKey: queryKeys.auth.customer(),
    queryFn: authApi.fetchCurrentCustomer,
    enabled: isHydrated && token !== null,
    retry: (failureCount, error) =>
      !(error instanceof ApiError && error.isUnauthenticated) && failureCount < 1,
    staleTime: 60_000,
  });

  useEffect(() => {
    if (query.data) {
      setUser(query.data);
    }
  }, [query.data, setUser]);

  useEffect(() => {
    if (query.error instanceof ApiError && query.error.isUnauthenticated) {
      clear();
    }
  }, [query.error, clear]);

  return query;
}

export function useLogin() {
  const setSession = useCustomerAuthStore((state) => state.setSession);
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: LoginInput) => authApi.login(input),
    onSuccess: (session) => {
      setSession(session.token, session.user);

      // Any data cached for the previous (or anonymous) session is now wrong.
      void queryClient.invalidateQueries();
    },
  });
}

export function useRegister() {
  const setSession = useCustomerAuthStore((state) => state.setSession);
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: RegisterInput) => authApi.register(input),
    onSuccess: (session) => {
      setSession(session.token, session.user);
      void queryClient.invalidateQueries();
    },
  });
}

export function useLogout() {
  const clear = useCustomerAuthStore((state) => state.clear);
  const queryClient = useQueryClient();
  const router = useRouter();

  return useMutation({
    mutationFn: () => authApi.logout(),
    // `onSettled`, not `onSuccess`: if the request fails because the token was
    // already revoked server-side, the user still expects to be signed out
    // locally. Leaving them "signed in" with a dead token is worse.
    onSettled: () => {
      clear();
      queryClient.clear();
      router.push('/login');
    },
  });
}

export function useForgotPassword() {
  return useMutation({ mutationFn: authApi.forgotPassword });
}

export function useResetPassword() {
  return useMutation({ mutationFn: authApi.resetPassword });
}

export function useChangePassword() {
  return useMutation({ mutationFn: authApi.changePassword });
}

export function useUpdateProfile() {
  const setUser = useCustomerAuthStore((state) => state.setUser);
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: authApi.updateProfile,
    onSuccess: (user) => {
      setUser(user);
      void queryClient.invalidateQueries({ queryKey: queryKeys.auth.customer() });
    },
  });
}

export function useResendVerification() {
  return useMutation({ mutationFn: authApi.resendVerificationEmail });
}
