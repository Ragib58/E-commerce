'use client';

import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';
import { registerTokenResolver } from '@/lib/api/auth-token';
import type { Customer } from '../types';

/**
 * Customer session state.
 *
 * Persisted to sessionStorage rather than localStorage: a token in
 * localStorage survives browser restarts and is readable by any script on the
 * origin, so an XSS bug becomes a lasting account compromise instead of a
 * tab-lifetime one. The eventual hardening is httpOnly cookies, which
 * JavaScript cannot read at all.
 *
 * Only the token and a minimal user snapshot are stored. The snapshot exists
 * so the first paint after a refresh is not a flash of signed-out UI; it is
 * replaced by a real /me fetch on mount, which is authoritative.
 */

interface CustomerAuthState {
  token: string | null;
  user: Customer | null;
  /** False until the store has rehydrated, so guards do not redirect early. */
  isHydrated: boolean;

  setSession: (token: string, user: Customer) => void;
  setUser: (user: Customer) => void;
  clear: () => void;
  markHydrated: () => void;
}

export const useCustomerAuthStore = create<CustomerAuthState>()(
  persist(
    (set) => ({
      token: null,
      user: null,
      isHydrated: false,

      setSession: (token, user) => set({ token, user }),
      setUser: (user) => set({ user }),
      clear: () => set({ token: null, user: null }),
      markHydrated: () => set({ isHydrated: true }),
    }),
    {
      name: 'customer-auth',
      storage: createJSONStorage(() => sessionStorage),
      partialize: (state) => ({ token: state.token, user: state.user }),
      onRehydrateStorage: () => (state) => {
        // Guards must wait for this; acting on a null token before rehydration
        // would bounce an authenticated user to the login page on every reload.
        state?.markHydrated();
      },
    },
  ),
);

// Lets the shared API client attach this realm's token without importing the
// store (which would create a cycle: client -> store -> api -> client).
registerTokenResolver('customer', () => useCustomerAuthStore.getState().token);

/** Read the token outside React, e.g. in an event handler. */
export function getCustomerToken(): string | null {
  return useCustomerAuthStore.getState().token;
}
