import type { Metadata } from 'next';
import { Suspense } from 'react';
import Link from 'next/link';

import { fetchCatalogFilters, fetchCategories, fetchProducts } from '@/features/catalog/api';
import { ProductGrid, ProductGridSkeleton } from '@/features/catalog/components/product-card';
import { CatalogPagination } from '@/features/catalog/components/catalog-pagination';
import { CatalogSort } from '@/features/catalog/components/catalog-sort';
import { ProductFilter } from '@/features/catalog/components/product-filter';
import { filterKey, toProductListParams } from '@/features/catalog/lib/search-params';
import type { ProductListParams } from '@/features/catalog/types';
import { getStoreConfig } from '@/features/settings/lib/get-store-config';
import type { StoreConfig } from '@/features/settings/lib/store-config';

/**
 * The shop — the full product listing.
 *
 * A server component. The grid renders on the server so a shopper and a crawler
 * both receive products in the HTML rather than after a client-side fetch, and
 * so a filtered URL is a real, shareable page.
 *
 * Only the controls ship JavaScript: the filter rail, the sort select, and the
 * per-card action buttons. That is the entire client bundle for this page.
 *
 * The grid streams inside a Suspense boundary keyed on the filter state, so
 * changing a filter shows a skeleton immediately rather than freezing on the
 * previous results with no feedback.
 */

interface ShopPageProps {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}

export async function generateMetadata({ searchParams }: ShopPageProps): Promise<Metadata> {
  const [{ config }, resolved] = await Promise.all([getStoreConfig(), searchParams]);

  const search = typeof resolved.search === 'string' ? resolved.search : undefined;

  return {
    title: search ? `Search: ${search}` : 'Shop',
    description: config.metaDescription,
    /*
     * A filtered or searched view is deliberately not indexed.
     *
     * Every combination of facets is a distinct URL, so indexing them puts
     * thousands of near-identical thin pages into search results and dilutes
     * the ranking of the canonical listing. `follow` stays on, so the products
     * linked from those pages are still discovered.
     */
    robots:
      config.indexable && Object.keys(resolved).length === 0
        ? { index: true, follow: true }
        : { index: false, follow: true },
    alternates: { canonical: '/products' },
  };
}

export default async function ShopPage({ searchParams }: ShopPageProps) {
  const resolved = await searchParams;
  const params = toProductListParams(resolved);

  // The rail's own data does not depend on the current filters, so it is
  // fetched alongside the config rather than after the products.
  const [{ config }, filters, categories] = await Promise.all([
    getStoreConfig(),
    fetchCatalogFilters(),
    fetchCategories(),
  ]);

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
          <span className="text-foreground">Shop</span>
        </nav>

        <h1 className="text-3xl font-semibold tracking-tight">
          {params.search ? `Results for “${params.search}”` : 'All products'}
        </h1>
      </header>

      <div className="grid gap-8 lg:grid-cols-[16rem_1fr]">
        <aside aria-label="Filters" className="hidden lg:sticky lg:top-24 lg:block lg:self-start">
          <ProductFilter filters={filters} categories={categories} />
        </aside>

        <div className="min-w-0">
          <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
            {/* The mobile trigger and sheet. Hidden on desktop, where the rail
                above is always visible. */}
            <div className="lg:hidden">
              <ProductFilter filters={filters} categories={categories} />
            </div>

            <CatalogSort sorts={filters.sorts} />
          </div>

          {/*
            Keyed on the filter state so a filter change remounts the boundary
            and shows the skeleton. Without the key React keeps the previous
            subtree mounted and the page appears frozen while the server works.
          */}
          <Suspense key={filterKey(resolved)} fallback={<ProductGridSkeleton />}>
            <ProductResults params={params} config={config} search={params.search} />
          </Suspense>
        </div>
      </div>
    </div>
  );
}

/**
 * The grid and its pagination.
 *
 * Its own async component so the filter rail and page chrome render
 * immediately while the product query streams in behind a skeleton.
 */
async function ProductResults({
  params,
  config,
  search,
}: {
  params: ProductListParams;
  config: StoreConfig;
  search?: string;
}) {
  const { products, pagination } = await fetchProducts(params);

  return (
    <>
      {pagination ? (
        <p className="mb-4 text-sm text-muted-foreground">
          {pagination.total} product{pagination.total === 1 ? '' : 's'}
        </p>
      ) : null}

      <ProductGrid
        products={products}
        config={config}
        emptyMessage={
          search
            ? `No products match “${search}”. Try a different search, or clear your filters.`
            : 'No products match these filters. Try widening your selection.'
        }
      />

      {pagination ? <CatalogPagination pagination={pagination} /> : null}
    </>
  );
}
