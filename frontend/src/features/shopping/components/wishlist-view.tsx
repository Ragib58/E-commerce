'use client';

import Link from 'next/link';
import { Heart, Loader2 } from 'lucide-react';

import { useStoreConfig } from '@/components/providers/store-config-provider';
import { useCustomerSession } from '@/features/auth/hooks/use-customer-auth';
import { ProductGrid, ProductGridSkeleton } from '@/features/catalog/components/product-card';
import { useWishlistProducts } from '../hooks/use-wishlist';

/**
 * The wishlist page.
 *
 * Renders the same `ProductCard` as every grid on the site, so a saved product
 * looks identical to a browsed one and its wishlist heart is already filled —
 * the toggle inside the card reads the same state this page does.
 *
 * All four states are explicit: loading, empty-as-guest, empty-as-customer, and
 * populated. The two empty states differ because the advice differs — a guest
 * should be told their list is device-local, which is the one thing that will
 * surprise them later.
 */
export function WishlistView() {
  const config = useStoreConfig();
  const { isAuthenticated, isHydrated } = useCustomerSession();
  const { products, isLoading, isError } = useWishlistProducts();

  return (
    <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6">
      <header className="mb-8">
        <h1 className="text-3xl font-semibold tracking-tight">Your wishlist</h1>
        {products.length > 0 ? (
          <p className="mt-1 text-sm text-muted-foreground">
            {products.length} saved item{products.length === 1 ? '' : 's'}
          </p>
        ) : null}
      </header>

      {/*
        Held until the session has rehydrated. Before that we do not know
        whether to read the server or localStorage, and guessing produces a
        flash of the wrong empty state.
      */}
      {!isHydrated ? (
        <div className="flex justify-center py-24">
          <Loader2 className="size-6 animate-spin text-muted-foreground" aria-hidden="true" />
          <span className="sr-only">Loading your wishlist…</span>
        </div>
      ) : isLoading ? (
        <ProductGridSkeleton count={4} />
      ) : isError ? (
        <div role="alert" className="rounded-lg border border-destructive/40 bg-destructive/5 p-6">
          <p className="text-sm font-medium text-destructive">
            Your wishlist could not be loaded
          </p>
          <p className="mt-1 text-sm text-muted-foreground">
            Nothing has been lost — try refreshing the page.
          </p>
        </div>
      ) : products.length === 0 ? (
        <EmptyWishlist isAuthenticated={isAuthenticated} />
      ) : (
        <>
          <ProductGrid products={products} config={config} />

          {!isAuthenticated ? (
            <p className="mt-8 rounded-lg border border-border bg-muted/40 p-4 text-sm text-muted-foreground">
              These items are saved on this device only.{' '}
              <Link href="/login" className="font-medium text-primary hover:underline">
                Sign in
              </Link>{' '}
              to keep them on your account and reach them from anywhere.
            </p>
          ) : null}
        </>
      )}
    </div>
  );
}

function EmptyWishlist({ isAuthenticated }: { isAuthenticated: boolean }) {
  return (
    <div className="flex flex-col items-center gap-3 rounded-lg border border-dashed border-border py-20 text-center">
      <Heart className="size-10 text-muted-foreground/40" aria-hidden="true" />
      <p className="text-base font-medium">Nothing saved yet</p>
      <p className="max-w-sm text-sm text-muted-foreground">
        Tap the heart on any product to save it here for later.
      </p>

      <Link
        href="/products"
        className="mt-2 rounded-md bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90"
      >
        Browse products
      </Link>

      {!isAuthenticated ? (
        <p className="mt-2 max-w-sm text-xs text-muted-foreground">
          Signing in keeps your saved items across devices.
        </p>
      ) : null}
    </div>
  );
}
