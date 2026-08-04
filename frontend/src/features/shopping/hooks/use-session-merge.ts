'use client';

import { useCallback } from 'react';

import { clearCartToken } from '@/lib/api/cart-token';
import { useMergeGuestCart } from '@/features/cart/hooks/use-cart';
import { getWishlistIdentifiers } from '../stores/wishlist-store';
import { useMergeWishlist } from './use-wishlist';

/**
 * Claim a guest's cart and wishlist after they sign in.
 *
 * Kept out of `useLogin` deliberately. The auth hooks know about sessions and
 * nothing else; making them import the cart and wishlist features would invert
 * the dependency — shopping already depends on auth to decide which wishlist is
 * authoritative, and a cycle between the two is how a refactor becomes
 * impossible.
 *
 * Returns a callable rather than running on mount: the caller knows when a
 * login has just completed. Running it on every mount would resend the local
 * wishlist on every page load.
 *
 * ## Failure is deliberately quiet
 *
 * Both merges are best-effort. The shopper is signed in either way, and their
 * own server-side cart and wishlist still load — surfacing a merge failure as
 * an error on the login screen would suggest the login itself failed. Each
 * mutation logs its own failure for diagnosis.
 */
export function useSessionMerge(): () => Promise<void> {
  const mergeCart = useMergeGuestCart();
  const mergeWishlist = useMergeWishlist();

  return useCallback(async () => {
    const savedProducts = getWishlistIdentifiers();

    /*
     * Run concurrently. They touch different resources, and making the wishlist
     * wait on the cart would double the delay before the header badges settle.
     * `allSettled` so one failing does not abandon the other.
     */
    await Promise.allSettled([
      mergeCart.mutateAsync(),
      savedProducts.length > 0 ? mergeWishlist.mutateAsync(savedProducts) : Promise.resolve([]),
    ]);

    /*
     * Drop the guest cart credential.
     *
     * The cart it identified has either been claimed by this account or was
     * empty. Keeping the token would mean every later request sends a header
     * the API ignores — and, if the shopper signs out, would resurrect a cart
     * that no longer exists.
     */
    clearCartToken();
  }, [mergeCart, mergeWishlist]);
}
