'use client';

import { create } from 'zustand';

/**
 * Cart *interface* state — whether the drawer is open.
 *
 * Deliberately the only cart state in Zustand. The cart's contents live in the
 * TanStack Query cache because the server owns them; putting them here too
 * would create two copies that drift, and the one the UI reads would be the
 * one that is wrong.
 *
 * The rule this encodes: **Zustand holds what the server does not know about.**
 * A drawer's open state is never persisted, never synced, and meaningless to
 * the API — so it belongs here and nowhere else.
 *
 * Not persisted: a drawer that reopens itself on every page load is an
 * irritation, not a restored session.
 */

interface CartUiState {
  isOpen: boolean;
  open: () => void;
  close: () => void;
  toggle: () => void;
}

export const useCartUiStore = create<CartUiState>()((set) => ({
  isOpen: false,
  open: () => set({ isOpen: true }),
  close: () => set({ isOpen: false }),
  toggle: () => set((state) => ({ isOpen: !state.isOpen })),
}));
