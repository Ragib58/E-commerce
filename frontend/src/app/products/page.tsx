import type { Metadata } from 'next';
import Link from 'next/link';
import { fetchCatalogFilters, fetchProducts } from '@/features/catalog/api';
import { ProductGrid } from '@/features/catalog/components/product-card';
import { CatalogPagination } from '@/features/catalog/components/catalog-pagination';
import { CatalogToolbar } from '@/features/catalog/components/catalog-toolbar';
import { getStoreConfig } from '@/features/settings/lib/get-store-config';
import type { ProductListParams } from '@/features/catalog/types';

/**
 * Product listing.
 *
 * A server component: the grid is static markup, and rendering it on the server
 * means a shopper (and a crawler) receives products in the HTML rather than
 * after a client-side fetch. Only the toolbar and pagination are interactive.
 */

export async function generateMetadata(): Promise<Metadata> {
  const { config } = await getStoreConfig();

  return {
    title: `Shop — ${config.companyName}`,
    description: config.metaDescription,
    robots: { index: config.indexable, follow: config.indexable },
  };
}

/**
 * Translate the URL's query string into API parameters.
 *
 * The URL is the source of truth for filter state: it survives a refresh, can
 * be shared, and lets the back button undo a filter. Holding it in React state
 * instead would lose all three.
 */
function toParams(searchParams: Record<string, string | string[] | undefined>): ProductListParams {
  const single = (key: string): string | undefined => {
    const value = searchParams[key];

    return Array.isArray(value) ? value[0] : value;
  };

  const attributes: Record<string, string[]> = {};

  for (const [key, value] of Object.entries(searchParams)) {
    const attributeSlug = key.match(/^attr_(.+)$/)?.[1];

    if (attributeSlug && value) {
      attributes[attributeSlug] = (Array.isArray(value) ? value : value.split(',')).filter(Boolean);
    }
  }

  const minPrice = single('min_price');
  const maxPrice = single('max_price');
  const brand = single('brand');

  return {
    search: single('search'),
    sort: single('sort'),
    page: single('page') ? Number(single('page')) : undefined,
    brand: brand ? brand.split(',').filter(Boolean) : undefined,
    min_price: minPrice ? Number(minPrice) : undefined,
    max_price: maxPrice ? Number(maxPrice) : undefined,
    in_stock: single('in_stock') === '1',
    attributes: Object.keys(attributes).length > 0 ? attributes : undefined,
  };
}

export default async function ProductsPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const resolved = await searchParams;
  const params = toParams(resolved);

  // Fetched concurrently: the filter rail and the grid do not depend on each
  // other, and awaiting them in sequence would double the page's latency.
  const [{ config }, { products, pagination }, filters] = await Promise.all([
    getStoreConfig(),
    fetchProducts(params),
    fetchCatalogFilters(),
  ]);

  return (
    <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6">
      <header className="mb-8">
        <nav aria-label="Breadcrumb" className="mb-2 text-sm text-muted-foreground">
          <Link href="/" className="hover:text-foreground">
            Home
          </Link>
          <span className="mx-2">/</span>
          <span className="text-foreground">Shop</span>
        </nav>

        <h1 className="text-3xl font-semibold tracking-tight">
          {params.search ? `Results for “${params.search}”` : 'All products'}
        </h1>

        {pagination ? (
          <p className="mt-1 text-sm text-muted-foreground">
            {pagination.total} product{pagination.total === 1 ? '' : 's'}
          </p>
        ) : null}
      </header>

      <CatalogToolbar filters={filters} sorts={filters.sorts} />

      <div className="mt-6">
        <ProductGrid
          products={products}
          config={config}
          emptyMessage={
            params.search
              ? `No products match “${params.search}”.`
              : 'No products are available yet.'
          }
        />
      </div>

      {pagination ? <CatalogPagination pagination={pagination} /> : null}
    </div>
  );
}
