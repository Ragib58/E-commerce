import { apiClient } from '@/lib/api/client';
import { cartSchema, EMPTY_CART, type AddToCartInput, type Cart } from '../types';

/**
 * Data access for the cart.
 *
 * Every endpoint returns the *whole* recomputed cart, so each function here
 * resolves to a complete `Cart` rather than to the line that changed. That is
 * what lets the query cache be replaced outright on every mutation instead of
 * being patched — and a patched cache is how a client's totals drift from the
 * server's.
 *
 * Never cached. A cart is per-visitor and changes on every interaction; an ISR
 * entry would be a cache of one, and a stale one would show a shopper someone
 * else's basket.
 */

const NO_CACHE = { cache: 'no-store' } as const;

/**
 * Parse an API response into a Cart.
 *
 * Falls back to the empty cart on a shape mismatch rather than throwing: a
 * malformed response should render an empty basket with the rest of the page
 * intact, not replace the storefront with an error boundary.
 */
function parseCart(data: unknown): Cart {
  const parsed = cartSchema.safeParse(data);

  if (!parsed.success) {
    console.error('[cart] Response failed validation.', parsed.error.flatten());

    return EMPTY_CART;
  }

  return parsed.data;
}

export async function fetchCart(): Promise<Cart> {
  const result = await apiClient.get<unknown>('/cart', NO_CACHE);

  return parseCart(result.data);
}

export async function addToCart(input: AddToCartInput): Promise<Cart> {
  const result = await apiClient.post<unknown>('/cart/items', {
    // Only these four keys. There is deliberately no price, and the API would
    // discard one anyway — see the types module.
    body: {
      product: input.product,
      variant: input.variant ?? undefined,
      quantity: input.quantity ?? 1,
      options: input.options ?? undefined,
    },
    ...NO_CACHE,
  });

  return parseCart(result.data);
}

/**
 * Set a line's quantity. Zero removes it, matching the API.
 */
export async function updateCartItem(itemId: number, quantity: number): Promise<Cart> {
  const result = await apiClient.patch<unknown>(`/cart/items/${itemId}`, {
    body: { quantity },
    ...NO_CACHE,
  });

  return parseCart(result.data);
}

export async function removeCartItem(itemId: number): Promise<Cart> {
  const result = await apiClient.delete<unknown>(`/cart/items/${itemId}`, NO_CACHE);

  return parseCart(result.data);
}

export async function clearCart(): Promise<Cart> {
  const result = await apiClient.delete<unknown>('/cart', NO_CACHE);

  return parseCart(result.data);
}

/**
 * Store a coupon code.
 *
 * No discount is applied in this phase; the response says so explicitly rather
 * than reporting a zero-value discount, which would render as a broken
 * promotion. See the API's CartService::applyCoupon.
 */
export async function applyCoupon(code: string | null): Promise<Cart> {
  const result = await apiClient.post<unknown>('/cart/coupon', {
    body: { coupon_code: code },
    ...NO_CACHE,
  });

  return parseCart(result.data);
}

/**
 * Claim a guest cart for the customer who has just signed in.
 *
 * Idempotent server-side, so a client that calls it more than once — on login
 * and again on a later page load — cannot double the quantities.
 */
export async function mergeGuestCart(): Promise<Cart> {
  const result = await apiClient.post<unknown>('/cart/merge', NO_CACHE);

  return parseCart(result.data);
}
