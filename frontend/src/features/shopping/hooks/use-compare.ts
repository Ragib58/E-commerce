'use client';

import { useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';

import { queryKeys } from '@/config/query-keys';
import type { Product } from '@/features/catalog/types';
import { lookupProducts } from '../api';
import { MAX_COMPARE_ITEMS, useCompareStore } from '../stores/compare-store';
import { useRecentlyViewedStore } from '../stores/recently-viewed-store';

/**
 * The comparison tray, and the products in it.
 *
 * Both the tray and the recently-viewed rail resolve identifiers through the
 * same bulk lookup, so a product's price and stock are always fresh — the
 * stores hold identifiers precisely so they cannot cache a stale product.
 */
export function useCompare() {
  const items = useCompareStore((state) => state.items);
  const isHydrated = useCompareStore((state) => state.isHydrated);
  const toggle = useCompareStore((state) => state.toggle);
  const remove = useCompareStore((state) => state.remove);
  const clear = useCompareStore((state) => state.clear);

  return {
    items,
    count: items.length,
    isFull: items.length >= MAX_COMPARE_ITEMS,
    max: MAX_COMPARE_ITEMS,
    /** Guards against rendering an active state the server did not render. */
    isReady: isHydrated,
    has: (identifier: string) => items.includes(identifier),
    /** False when the tray is full, so the caller can explain the refusal. */
    toggle,
    remove,
    clear,
  };
}

export function useCompareProducts(): {
  products: Product[];
  isLoading: boolean;
  isError: boolean;
} {
  const items = useCompareStore((state) => state.items);
  const isHydrated = useCompareStore((state) => state.isHydrated);

  const query = useQuery({
    // Keyed on the list itself: removing a product must refetch rather than
    // serve a cached table that still shows it.
    queryKey: queryKeys.compare.products(items),
    queryFn: () => lookupProducts(items),
    enabled: isHydrated && items.length > 0,
    staleTime: 60_000,
  });

  return {
    products: query.data ?? [],
    isLoading: items.length > 0 && query.isLoading,
    isError: query.isError,
  };
}

/**
 * Recently viewed products, resolved.
 *
 * `exclude` drops the product currently being viewed: a "recently viewed" rail
 * whose first tile is the page you are on is noise.
 */
export function useRecentlyViewed(exclude?: string): {
  products: Product[];
  isLoading: boolean;
  clear: () => void;
} {
  const items = useRecentlyViewedStore((state) => state.items);
  const isHydrated = useRecentlyViewedStore((state) => state.isHydrated);
  const clear = useRecentlyViewedStore((state) => state.clear);

  const identifiers = exclude ? items.filter((item) => item !== exclude) : items;

  const query = useQuery({
    queryKey: queryKeys.recentlyViewed.products(identifiers),
    queryFn: () => lookupProducts(identifiers),
    enabled: isHydrated && identifiers.length > 0,
    staleTime: 60_000,
  });

  return {
    products: query.data ?? [],
    isLoading: identifiers.length > 0 && query.isLoading,
    clear,
  };
}

/**
 * Record a product view.
 *
 * Called from the product detail page. The store is a no-op when the product is
 * already at the front of the list, so revisiting the same page does not
 * re-render every consumer of the rail.
 */
export function useRecordProductView(identifier: string | undefined): void {
  const record = useRecentlyViewedStore((state) => state.record);

  useEffect(() => {
    if (identifier) record(identifier);
  }, [identifier, record]);
}
