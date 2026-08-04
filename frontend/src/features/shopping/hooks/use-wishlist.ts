'use client';

import { useCallback } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { queryKeys } from '@/config/query-keys';
import { useCustomerSession } from '@/features/auth/hooks/use-customer-auth';
import type { Product } from '@/features/catalog/types';
import {
  addToWishlist,
  fetchWishlist,
  lookupProducts,
  mergeWishlist,
  removeFromWishlist,
} from '../api';
import { useWishlistStore } from '../stores/wishlist-store';

/**
 * The wishlist, whichever kind the visitor has.
 *
 * A signed-in customer's lives on the server; a guest's lives in localStorage.
 * This hook is the seam: components ask "is this saved?" and "toggle this" and
 * never learn which. Without it every product card would carry an
 * `isAuthenticated ? … : …` branch, and the two branches would drift.
 *
 * Both paths hold *identifiers* only. Products are fetched fresh in both cases,
 * so a saved item never shows a stale price.
 */

interface UseWishlistResult {
  /** Identifiers of everything saved. */
  saved: string[];
  isSaved: (identifier: string) => boolean;
  toggle: (identifier: string) => void;
  remove: (identifier: string) => void;
  count: number;
  /**
   * False until client state has rehydrated.
   *
   * Consumers must not render a filled heart before this: the server rendered
   * the empty state, so drawing a filled one on the first client pass is a
   * hydration mismatch as well as a visible flicker.
   */
  isReady: boolean;
  isPending: boolean;
}

export function useWishlist(): UseWishlistResult {
  const queryClient = useQueryClient();
  const { isAuthenticated, isHydrated: isSessionHydrated } = useCustomerSession();

  const localItems = useWishlistStore((state) => state.items);
  const localToggle = useWishlistStore((state) => state.toggle);
  const localRemove = useWishlistStore((state) => state.remove);
  const isLocalHydrated = useWishlistStore((state) => state.isHydrated);

  /*
   * The server's saved identifiers.
   *
   * Derived from the wishlist products query rather than fetched separately:
   * the two would otherwise be two round trips describing the same thing, and
   * could disagree between them.
   */
  const serverQuery = useQuery({
    queryKey: queryKeys.wishlist.list(),
    queryFn: fetchWishlist,
    enabled: isAuthenticated,
    staleTime: 60_000,
  });

  const serverSaved = (serverQuery.data ?? []).map((product) => product.id);

  const mutation = useMutation({
    mutationFn: ({ identifier, saved }: { identifier: string; saved: boolean }) =>
      saved ? removeFromWishlist(identifier) : addToWishlist(identifier),
    // The mutation returns the full saved set, but the *products* behind it
    // have changed too — so this is one of the few places a refetch is right
    // rather than a cache write.
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.wishlist.all });
    },
  });

  const saved = isAuthenticated ? serverSaved : localItems;

  const isSaved = useCallback(
    (identifier: string) => saved.includes(identifier),
    [saved],
  );

  const toggle = useCallback(
    (identifier: string) => {
      if (isAuthenticated) {
        mutation.mutate({ identifier, saved: serverSaved.includes(identifier) });

        return;
      }

      localToggle(identifier);
    },
    [isAuthenticated, localToggle, mutation, serverSaved],
  );

  const remove = useCallback(
    (identifier: string) => {
      if (isAuthenticated) {
        mutation.mutate({ identifier, saved: true });

        return;
      }

      localRemove(identifier);
    },
    [isAuthenticated, localRemove, mutation],
  );

  return {
    saved,
    isSaved,
    toggle,
    remove,
    count: saved.length,
    // Both must be settled: the session decides *which* source is
    // authoritative, so acting before it resolves can read the wrong one.
    isReady: isSessionHydrated && (isAuthenticated ? !serverQuery.isLoading : isLocalHydrated),
    isPending: mutation.isPending,
  };
}

/**
 * The products behind the wishlist, for the wishlist page.
 *
 * A signed-in customer gets them from the server; a guest's local identifiers
 * are resolved through the bulk lookup. Same output either way.
 */
export function useWishlistProducts(): {
  products: Product[];
  isLoading: boolean;
  isError: boolean;
} {
  const { isAuthenticated, isHydrated } = useCustomerSession();
  const localItems = useWishlistStore((state) => state.items);
  const isLocalHydrated = useWishlistStore((state) => state.isHydrated);

  const serverQuery = useQuery({
    queryKey: queryKeys.wishlist.list(),
    queryFn: fetchWishlist,
    enabled: isHydrated && isAuthenticated,
    staleTime: 60_000,
  });

  const guestQuery = useQuery({
    // Keyed on the identifier list, so removing an item refetches rather than
    // serving a cached page that still contains it.
    queryKey: queryKeys.wishlist.guest(localItems),
    queryFn: () => lookupProducts(localItems),
    enabled: isHydrated && !isAuthenticated && isLocalHydrated && localItems.length > 0,
    staleTime: 60_000,
  });

  if (isAuthenticated) {
    return {
      products: serverQuery.data ?? [],
      isLoading: serverQuery.isLoading,
      isError: serverQuery.isError,
    };
  }

  return {
    products: guestQuery.data ?? [],
    // An empty local list resolves instantly — there is nothing to fetch, and
    // reporting "loading" would render a spinner that never resolves.
    isLoading: localItems.length > 0 && guestQuery.isLoading,
    isError: guestQuery.isError,
  };
}

/**
 * Merge a guest wishlist into the account after sign-in.
 *
 * Returns a callable rather than running on mount: the caller knows when a
 * login has just completed, and running this on every mount would re-send the
 * local list on every page load.
 */
export function useMergeWishlist() {
  const queryClient = useQueryClient();
  const clearLocal = useWishlistStore((state) => state.clear);

  return useMutation({
    mutationFn: (identifiers: string[]) => mergeWishlist(identifiers),
    onSuccess: () => {
      // The local list has been folded into the account; keeping it would
      // resurrect deliberately removed items on the next sign-in.
      clearLocal();
      void queryClient.invalidateQueries({ queryKey: queryKeys.wishlist.all });
    },
    onError: (error) => {
      // Non-fatal: the customer is signed in regardless, and their server
      // wishlist still loads. The local list is left alone so a retry is
      // possible.
      console.error('[shopping] Wishlist merge failed.', error);
    },
  });
}
