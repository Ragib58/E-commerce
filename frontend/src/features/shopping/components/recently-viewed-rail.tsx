'use client';

import { useStoreConfig } from '@/components/providers/store-config-provider';
import { ProductGrid } from '@/features/catalog/components/product-card';
import { useRecentlyViewed } from '../hooks/use-compare';

/**
 * The recently-viewed rail.
 *
 * Renders nothing at all when there is no history — not a heading over empty
 * space, and not a skeleton. A visitor on their first product page has no
 * recently-viewed products, and showing them a placeholder for a section that
 * will never populate on this visit is noise.
 *
 * The list is per-device and lives in localStorage, so this is necessarily a
 * client component: the server has no way to know what this browser has seen.
 */
export function RecentlyViewedRail({
  /** The product being viewed, excluded so the rail is not led by this page. */
  exclude,
  title = 'Recently viewed',
}: {
  exclude?: string;
  title?: string;
}) {
  const config = useStoreConfig();
  const { products, isLoading, clear } = useRecentlyViewed(exclude);

  // Nothing to show, or still resolving on a first paint — either way the
  // section is absent rather than reserved.
  if (isLoading || products.length === 0) return null;

  return (
    <section aria-labelledby="recently-viewed-heading" className="border-t border-border pt-10">
      <div className="mb-6 flex items-center justify-between gap-3">
        <h2 id="recently-viewed-heading" className="text-xl font-semibold tracking-tight">
          {title}
        </h2>

        <button
          type="button"
          onClick={clear}
          className="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
        >
          Clear
        </button>
      </div>

      {/*
        Quick-add is off here. This rail is a navigation aid — "take me back to
        what I was looking at" — and a buy button competes with the primary
        action on whatever page it appears beneath.
      */}
      <ProductGrid products={products} config={config} showQuickAdd={false} />
    </section>
  );
}
