import type { Metadata } from 'next';
import { Suspense } from 'react';
import Link from 'next/link';
import { redirect } from 'next/navigation';
import { SearchX } from 'lucide-react';

import { fetchCatalogFilters, fetchProducts } from '@/features/catalog/api';
import { ProductGrid, ProductGridSkeleton } from '@/features/catalog/components/product-card';
import { CatalogPagination } from '@/features/catalog/components/catalog-pagination';
import { CatalogSort } from '@/features/catalog/components/catalog-sort';
import { ProductFilter } from '@/features/catalog/components/product-filter';
import { filterKey, toProductListParams } from '@/features/catalog/lib/search-params';
import type { ProductListParams } from '@/features/catalog/types';
import { getStoreConfig } from '@/features/settings/lib/get-store-config';
import type { StoreConfig } from '@/features/settings/lib/store-config';

/**
 * Search results.
 *
 * A distinct route from `/products?search=`, rather than a redirect to it.
 * Search is how most shoppers arrive at the catalog, and it deserves its own
 * URL: it is what the header form submits to, what appears in browser history
 * as "search", and what analytics can attribute separately from browsing.
 *
 * The results themselves come from the same listing endpoint with the same
 * filters — a searched view is a filtered view — so the two pages share every
 * component below.
 */

interface SearchPageProps {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}

export async function generateMetadata({ searchParams }: SearchPageProps): Promise<Metadata> {
  const [{ config }, resolved] = await Promise.all([getStoreConfig(), searchParams]);

  const query = typeof resolved.q === 'string' ? resolved.q : '';

  return {
    title: query ? `Search: ${query}` : 'Search',
    description: `Search the ${config.companyName} catalog.`,
    /*
     * Never indexed, regardless of the store-wide setting.
     *
     * Search result pages are unbounded — one URL per query anyone has ever
     * typed — and indexing them fills a search engine with thin, duplicated
     * pages. `follow` stays on so the products they link to are still crawled.
     */
    robots: { index: false, follow: true },
  };
}

export default async function SearchPage({ searchParams }: SearchPageProps) {
  const resolved = await searchParams;

  /*
   * `q` is the public parameter; the API's is `search`.
   *
   * `q` is what shoppers recognise in a URL and what other sites link with.
   * Translating here keeps that convention at the edge rather than renaming
   * the API's parameter to match a UI decision.
   */
  const query = typeof resolved.q === 'string' ? resolved.q.trim() : '';

  // A bare /search with no query has nothing to show and no useful empty state
  // that the shop page does not already provide.
  if (query === '') {
    redirect('/products');
  }

  const params: ProductListParams = {
    ...toProductListParams(resolved),
    search: query,
  };

  const [{ config }, filters] = await Promise.all([getStoreConfig(), fetchCatalogFilters()]);

  return (
    <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6">
      <header className="mb-6">
        <nav aria-label="Breadcrumb" className="mb-2 text-sm text-muted-foreground">
          <Link href="/" className="hover:text-foreground">
            Home
          </Link>
          <span className="mx-2" aria-hidden="true">
            /
          </span>
          <span className="text-foreground">Search</span>
        </nav>

        <h1 className="text-3xl font-semibold tracking-tight">
          Results for “{query}”
        </h1>
      </header>

      <div className="grid gap-8 lg:grid-cols-[16rem_1fr]">
        <aside aria-label="Filters" className="hidden lg:sticky lg:top-24 lg:block lg:self-start">
          <ProductFilter filters={filters} />
        </aside>

        <div className="min-w-0">
          <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div className="lg:hidden">
              <ProductFilter filters={filters} />
            </div>

            <CatalogSort sorts={filters.sorts} />
          </div>

          <Suspense key={filterKey(resolved)} fallback={<ProductGridSkeleton />}>
            <SearchResults params={params} config={config} query={query} />
          </Suspense>
        </div>
      </div>
    </div>
  );
}

async function SearchResults({
  params,
  config,
  query,
}: {
  params: ProductListParams;
  config: StoreConfig;
  query: string;
}) {
  const { products, pagination } = await fetchProducts(params);

  /*
   * A dedicated no-results state rather than the grid's generic empty message.
   *
   * A shopper who searched and found nothing needs a way forward — clearing
   * filters, or browsing the catalog — and an unadorned "no products match"
   * leaves them at a dead end.
   */
  if (products.length === 0) {
    return (
      <div className="flex flex-col items-center gap-3 rounded-lg border border-dashed border-border py-20 text-center">
        <SearchX className="size-10 text-muted-foreground/40" aria-hidden="true" />
        <p className="text-base font-medium">No results for “{query}”</p>
        <p className="max-w-sm text-sm text-muted-foreground">
          Check the spelling, try a more general term, or clear any filters you have applied.
        </p>
        <Link
          href="/products"
          className="mt-2 rounded-md bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90"
        >
          Browse all products
        </Link>
      </div>
    );
  }

  return (
    <>
      {pagination ? (
        <p className="mb-4 text-sm text-muted-foreground">
          {pagination.total} result{pagination.total === 1 ? '' : 's'}
        </p>
      ) : null}

      <ProductGrid products={products} config={config} />

      {pagination ? <CatalogPagination pagination={pagination} /> : null}
    </>
  );
}
