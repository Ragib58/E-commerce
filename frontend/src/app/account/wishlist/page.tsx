'use client';

import { useStoreConfig } from '@/components/providers/store-config-provider';
import { ProductGrid, ProductGridSkeleton } from '@/features/catalog/components/product-card';
import { useWishlistProducts } from '@/features/shopping/hooks/use-wishlist';

/**
 * Saved products, inside the account area.
 *
 * Shares `useWishlistProducts` with the standalone `/wishlist` page rather than
 * refetching independently, so both read the same TanStack Query entry and
 * cannot disagree about what is saved.
 *
 * The two pages exist for different journeys: `/wishlist` is reachable from the
 * header on any page, while this one sits in the account navigation beside
 * orders and profile. Neither is a redirect to the other, because a guest can
 * reach the first and not the second.
 */
export default function AccountWishlistPage() {
  const config = useStoreConfig();
  const { products, isLoading, isError } = useWishlistProducts();

  return (
    <section aria-labelledby="wishlist-heading" className="rounded-lg border border-border p-6">
      <h2 id="wishlist-heading" className="text-base font-semibold">
        Saved items
      </h2>
      <p className="mt-1 text-sm text-muted-foreground">
        {products.length > 0
          ? `${products.length} product${products.length === 1 ? '' : 's'} saved to your account.`
          : 'Products you save are kept on your account and available on any device.'}
      </p>

      <div className="mt-6">
        {isLoading ? (
          <ProductGridSkeleton count={3} />
        ) : isError ? (
          <div role="alert" className="rounded-lg border border-destructive/40 bg-destructive/5 p-4">
            <p className="text-sm font-medium text-destructive">
              Your saved items could not be loaded
            </p>
            <p className="mt-1 text-sm text-muted-foreground">
              Nothing has been lost — try refreshing the page.
            </p>
          </div>
        ) : (
          <ProductGrid
            products={products}
            config={config}
            columns={3}
            emptyMessage="You have not saved anything yet. Tap the heart on any product to save it."
          />
        )}
      </div>
    </section>
  );
}
