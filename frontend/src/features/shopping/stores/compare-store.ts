'use client';

import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';

/**
 * The comparison tray.
 *
 * Entirely client-side, with no server counterpart — deliberately. Comparison
 * is a within-session act: a shopper lines up three kettles, picks one, and
 * never wants that list again. Persisting it to the database would cost a
 * table, a merge path, and a set of endpoints for data whose useful life is
 * measured in minutes.
 *
 * As with the wishlist, only identifiers are stored. The products themselves
 * are fetched fresh, so a comparison table never shows a stale price.
 */

/**
 * Four is the ceiling because four is what fits.
 *
 * A comparison table is read by scanning across columns, and beyond four the
 * columns are too narrow to read on a laptop and require horizontal scrolling
 * on a phone — at which point the shopper can no longer see the rows they are
 * comparing, which is the entire point of the feature.
 */
export const MAX_COMPARE_ITEMS = 4;

interface CompareState {
  items: string[];
  isHydrated: boolean;

  /** Returns false when the tray is full, so the caller can explain why. */
  add: (identifier: string) => boolean;
  remove: (identifier: string) => void;
  toggle: (identifier: string) => boolean;
  clear: () => void;
  has: (identifier: string) => boolean;
  isFull: () => boolean;
  markHydrated: () => void;
}

export const useCompareStore = create<CompareState>()(
  persist(
    (set, get) => ({
      items: [],
      isHydrated: false,

      add: (identifier) => {
        const { items } = get();

        if (items.includes(identifier)) return true;
        if (items.length >= MAX_COMPARE_ITEMS) return false;

        // Appended rather than prepended: the tray reads left to right in the
        // order the shopper chose, and reordering it under them on each add
        // would make the comparison harder to follow.
        set({ items: [...items, identifier] });

        return true;
      },

      remove: (identifier) =>
        set((state) => ({ items: state.items.filter((item) => item !== identifier) })),

      toggle: (identifier) => {
        const { items, add, remove } = get();

        if (items.includes(identifier)) {
          remove(identifier);

          return true;
        }

        return add(identifier);
      },

      clear: () => set({ items: [] }),

      has: (identifier) => get().items.includes(identifier),

      isFull: () => get().items.length >= MAX_COMPARE_ITEMS,

      markHydrated: () => set({ isHydrated: true }),
    }),
    {
      name: 'compare',
      storage: createJSONStorage(() => localStorage),
      partialize: (state) => ({ items: state.items }),
      onRehydrateStorage: () => (state) => {
        state?.markHydrated();
      },
    },
  ),
);
