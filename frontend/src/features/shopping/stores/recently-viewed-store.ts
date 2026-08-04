'use client';

import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';

/**
 * Recently viewed products.
 *
 * Client-only and identifier-only, for the same reasons as the compare tray:
 * the list is per-device browsing history, and storing it server-side would
 * turn an incidental convenience into a tracked behavioural record with the
 * retention and privacy obligations that carries.
 *
 * The ordering *is* the data — "recently" is the whole feature — so re-viewing
 * a product moves it to the front rather than leaving it where it was.
 */

/**
 * Ten is enough to be useful and short enough to render as a single rail
 * without its own pagination. It also bounds the bulk lookup: the API caps that
 * request at 24 identifiers.
 */
const MAX_ITEMS = 10;

interface RecentlyViewedState {
  items: string[];
  isHydrated: boolean;

  record: (identifier: string) => void;
  clear: () => void;
  markHydrated: () => void;
}

export const useRecentlyViewedStore = create<RecentlyViewedState>()(
  persist(
    (set) => ({
      items: [],
      isHydrated: false,

      record: (identifier) =>
        set((state) => {
          // Already at the front: nothing changes. Returning the same state
          // object matters — a new array here would re-render every consumer
          // on each visit to a product page the shopper is already on.
          if (state.items[0] === identifier) return state;

          return {
            items: [
              identifier,
              ...state.items.filter((item) => item !== identifier),
            ].slice(0, MAX_ITEMS),
          };
        }),

      clear: () => set({ items: [] }),

      markHydrated: () => set({ isHydrated: true }),
    }),
    {
      name: 'recently-viewed',
      storage: createJSONStorage(() => localStorage),
      partialize: (state) => ({ items: state.items }),
      onRehydrateStorage: () => (state) => {
        state?.markHydrated();
      },
    },
  ),
);
