'use client';

import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';

/**
 * The guest wishlist.
 *
 * Holds product identifiers only — never product data. A cached product would
 * go stale the moment its price or availability changed, and a wishlist showing
 * last month's price is worse than one that fetches fresh.
 *
 * ## Guest versus signed-in
 *
 * A signed-in customer's wishlist lives on the server, because its whole value
 * is outliving the session and following them between devices. A guest has no
 * server identity to hang one on, so theirs lives here and is merged into the
 * account on sign-in.
 *
 * This store therefore stays authoritative *only* while signed out. Once
 * authenticated, `useWishlist` reads the server and this store becomes a
 * staging area for the next merge.
 *
 * localStorage rather than sessionStorage, unlike the auth token: a wishlist is
 * not a credential, and one that vanished when the tab closed would not be a
 * wishlist at all.
 */

/** A wishlist nobody curates beyond this; also bounds localStorage growth. */
const MAX_ITEMS = 200;

interface WishlistState {
  /** Product uuids or slugs, newest first. */
  items: string[];
  isHydrated: boolean;

  toggle: (identifier: string) => void;
  add: (identifier: string) => void;
  remove: (identifier: string) => void;
  clear: () => void;
  has: (identifier: string) => boolean;
  markHydrated: () => void;
}

export const useWishlistStore = create<WishlistState>()(
  persist(
    (set, get) => ({
      items: [],
      isHydrated: false,

      add: (identifier) =>
        set((state) =>
          state.items.includes(identifier)
            ? state
            : { items: [identifier, ...state.items].slice(0, MAX_ITEMS) },
        ),

      remove: (identifier) =>
        set((state) => ({ items: state.items.filter((item) => item !== identifier) })),

      toggle: (identifier) =>
        set((state) =>
          state.items.includes(identifier)
            ? { items: state.items.filter((item) => item !== identifier) }
            : { items: [identifier, ...state.items].slice(0, MAX_ITEMS) },
        ),

      clear: () => set({ items: [] }),

      has: (identifier) => get().items.includes(identifier),

      markHydrated: () => set({ isHydrated: true }),
    }),
    {
      name: 'wishlist',
      storage: createJSONStorage(() => localStorage),
      partialize: (state) => ({ items: state.items }),
      onRehydrateStorage: () => (state) => {
        /*
         * Components must wait for this before rendering a filled heart.
         * Rendering from an empty pre-hydration store and then correcting it
         * produces a visible flicker on every card, and — worse — a React
         * hydration mismatch, because the server rendered the empty state.
         */
        state?.markHydrated();
      },
    },
  ),
);

/** Read outside React, e.g. when merging after login. */
export function getWishlistIdentifiers(): string[] {
  return useWishlistStore.getState().items;
}
