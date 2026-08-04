'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { queryKeys } from '@/config/query-keys';
import { ApiError } from '@/lib/api/errors';
import {
  addToCart,
  applyCoupon,
  clearCart,
  fetchCart,
  mergeGuestCart,
  removeCartItem,
  updateCartItem,
} from '../api';
import { EMPTY_CART, type AddToCartInput, type Cart } from '../types';
import { useCartUiStore } from '../stores/cart-ui-store';

/**
 * Cart state, via TanStack Query.
 *
 * The server is the single source of truth for the cart, so it lives in the
 * query cache rather than in Zustand. Zustand holds only what the server does
 * not know about — whether the drawer is open — which is the division that
 * keeps the two from disagreeing.
 *
 * Every mutation writes the server's response straight into the cache with
 * `setQueryData` rather than invalidating and refetching. Two reasons: the API
 * already returns the whole recomputed cart, so a refetch would be a second
 * round trip for data already in hand; and the window between the mutation
 * resolving and the refetch landing is exactly when a shopper sees a stale
 * total.
 *
 * Nothing here is optimistic. An optimistic cart has to guess the new subtotal,
 * which means computing prices on the client — the one thing this whole feature
 * is built to avoid. The mutations are fast, and each shows pending state.
 */

export function useCart() {
  const query = useQuery({
    queryKey: queryKeys.cart.detail(),
    queryFn: fetchCart,
    // Cart contents change through this client's own mutations, which write the
    // result into the cache directly. Refetching on focus would replace a
    // correct cart with an identical one on every tab switch.
    staleTime: 30_000,
    // Degrades to an empty cart rather than undefined, so consumers never
    // null-guard and a failed fetch renders an empty basket, not a crash.
    placeholderData: EMPTY_CART,
  });

  return {
    cart: query.data ?? EMPTY_CART,
    isLoading: query.isLoading,
    isFetching: query.isFetching,
    isError: query.isError,
    error: query.error,
    refetch: query.refetch,
  };
}

/**
 * Write a server response into the cache.
 *
 * Shared by every mutation so there is exactly one place the cart cache is
 * updated, and no path that patches it partially.
 */
function useApplyCart() {
  const queryClient = useQueryClient();

  return (cart: Cart) => queryClient.setQueryData(queryKeys.cart.detail(), cart);
}

export function useAddToCart() {
  const applyCart = useApplyCart();
  const open = useCartUiStore((state) => state.open);

  return useMutation({
    mutationFn: (input: AddToCartInput) => addToCart(input),
    onSuccess: (cart) => {
      applyCart(cart);
      // Opening the drawer is the confirmation. A toast that disappears leaves
      // a shopper unsure whether the click registered.
      open();
    },
  });
}

export function useUpdateCartItem() {
  const applyCart = useApplyCart();

  return useMutation({
    mutationFn: ({ itemId, quantity }: { itemId: number; quantity: number }) =>
      updateCartItem(itemId, quantity),
    onSuccess: applyCart,
  });
}

export function useRemoveCartItem() {
  const applyCart = useApplyCart();

  return useMutation({
    mutationFn: (itemId: number) => removeCartItem(itemId),
    onSuccess: applyCart,
  });
}

export function useClearCart() {
  const applyCart = useApplyCart();

  return useMutation({
    mutationFn: clearCart,
    onSuccess: applyCart,
  });
}

export function useApplyCoupon() {
  const applyCart = useApplyCart();

  return useMutation({
    mutationFn: (code: string | null) => applyCoupon(code),
    onSuccess: applyCart,
  });
}

/**
 * Claim the guest cart after signing in.
 *
 * Failure is swallowed deliberately. This runs immediately after a successful
 * login, and a merge that fails must not make the login look like it failed —
 * the customer is signed in either way, and their own cart still loads. The
 * error is logged for diagnosis rather than surfaced.
 */
export function useMergeGuestCart() {
  const applyCart = useApplyCart();

  return useMutation({
    mutationFn: mergeGuestCart,
    onSuccess: applyCart,
    onError: (error) => {
      console.error(
        '[cart] Guest cart merge failed.',
        error instanceof ApiError ? error.message : error,
      );
    },
  });
}
