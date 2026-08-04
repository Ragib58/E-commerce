import { z } from 'zod';

import { apiClient } from '@/lib/api/client';
import { productSchema, type Product } from '@/features/catalog/types';

/**
 * Data access for the wishlist and for bulk product lookup.
 *
 * The lookup endpoint serves both the compare tray and the recently-viewed
 * rail: each holds a list of identifiers on the client and needs the products
 * behind them, resolved in the order asked for.
 */

const NO_CACHE = { cache: 'no-store' } as const;

/**
 * Resolve product identifiers to full products, in the requested order.
 *
 * POST despite being a read — a list of twenty-plus uuids overflows practical
 * URL length limits, and a truncated query string would silently drop products
 * rather than failing visibly.
 *
 * Returns an empty array rather than throwing: a recently-viewed rail that
 * cannot load should be absent, not fatal to the page around it.
 */
export async function lookupProducts(identifiers: string[]): Promise<Product[]> {
  if (identifiers.length === 0) return [];

  try {
    const result = await apiClient.post<unknown>('/catalog/products/lookup', {
      // The API caps this at 24; trimming here avoids a validation error for
      // what is, from the shopper's side, an ordinary long history.
      body: { products: identifiers.slice(0, 24) },
      ...NO_CACHE,
    });

    return z.array(productSchema).catch([]).parse(result.data);
  } catch (error) {
    console.error('[shopping] Product lookup failed.', error);

    return [];
  }
}

/** The signed-in customer's saved products. */
export async function fetchWishlist(): Promise<Product[]> {
  const result = await apiClient.get<unknown>('/wishlist', NO_CACHE);

  return z.array(productSchema).catch([]).parse(result.data);
}

const savedResponseSchema = z.object({
  saved: z.array(z.string()).default([]),
});

/**
 * Save a product. Idempotent server-side.
 *
 * Returns the full set of saved identifiers rather than just the new one, so
 * the client's notion of what is saved is replaced wholesale on every mutation
 * instead of being patched — the same reasoning as the cart.
 */
export async function addToWishlist(identifier: string): Promise<string[]> {
  const result = await apiClient.post<unknown>('/wishlist', {
    body: { product: identifier },
    ...NO_CACHE,
  });

  return savedResponseSchema.catch({ saved: [] }).parse(result.data).saved;
}

export async function removeFromWishlist(identifier: string): Promise<string[]> {
  const result = await apiClient.delete<unknown>(
    `/wishlist/${encodeURIComponent(identifier)}`,
    NO_CACHE,
  );

  return savedResponseSchema.catch({ saved: [] }).parse(result.data).saved;
}

/**
 * Fold a guest's local wishlist into the account's, after sign-in.
 *
 * Unknown or withdrawn identifiers are skipped server-side rather than failing
 * the whole merge, so one stale localStorage entry cannot cost a shopper the
 * rest of their saved items.
 */
export async function mergeWishlist(identifiers: string[]): Promise<string[]> {
  if (identifiers.length === 0) return [];

  const result = await apiClient.post<unknown>('/wishlist/merge', {
    body: { products: identifiers.slice(0, 200) },
    ...NO_CACHE,
  });

  return savedResponseSchema.catch({ saved: [] }).parse(result.data).saved;
}
