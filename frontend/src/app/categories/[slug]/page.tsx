import type { Metadata } from 'next';
import { Suspense } from 'react';
import Link from 'next/link';
import { notFound } from 'next/navigation';

import { fetchCatalogFilters, fetchCategoryPage } from '@/features/catalog/api';
import { ProductGrid, ProductGridSkeleton } from '@/features/catalog/components/product-card';
import { CatalogPagination } from '@/features/catalog/components/catalog-pagination';
import { CatalogSort } from '@/features/catalog/components/catalog-sort';
import { ProductFilter } from '@/features/catalog/components/product-filter';
import { filterKey, toProductListParams } from '@/features/catalog/lib/search-params';
import { getStoreConfig } from '@/features/settings/lib/get-store-config';
import type { StoreConfig } from '@/features/settings/lib/store-config';

/**
 * A single category's products.
 *
 * The listing includes everything filed in the category's descendants, which is
 * what a shopper expects: clicking "Clothing" should show the shirts under
 * "Clothing > Shirts", not an empty page. The API applies that; this page only
 * names the category.
 *
 * The category facet is hidden in the rail here — the page is already scoped to
 * one, and a second category selector inside it would produce a URL whose two
 * category constraints contradict each other.
 */

interface PageProps {
  params: Promise<{ slug: string }>;
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}

export async function generateMetadata({ params, searchParams }: PageProps): Promise<Metadata> {
  const [{ slug }, resolved] = await Promise.all([params, searchParams]);

  const [page, { config }] = await Promise.all([fetchCategoryPage(slug), getStoreConfig()]);

  if (!page) {
    return { title: 'Category not found', robots: { index: false, follow: false } };
  }

  const title = page.category.seo?.meta_title ?? page.category.name;
  const isFiltered = Object.keys(resolved).length > 0;

  return {
    title,
    description: page.category.seo?.meta_description ?? page.category.description ?? undefined,
    alternates: { canonical: `/categories/${page.category.slug}` },
    // The unfiltered category page is the canonical one; its facet permutations
    // are not indexed. See the shop page for the reasoning.
    robots:
      config.indexable && !isFiltered
        ? { index: true, follow: true }
        : { index: false, follow: true },
    openGraph: {
      title,
      description: page.category.description ?? undefined,
      images: page.category.banner ? [{ url: page.category.banner }] : undefined,
    },
  };
}

export default async function CategoryPage({ params, searchParams }: PageProps) {
  const [{ slug }, resolved] = await Promise.all([params, searchParams]);

  /*
   * Fetched without filters.
   *
   * This call is only for the category record and its children, which the
   * header and subcategory nav need before the grid resolves. The filtered
   * listing streams separately below, so a slow product query does not hold
   * back the page's identity.
   */
  const [page, { config }, filters] = await Promise.all([
    fetchCategoryPage(slug),
    getStoreConfig(),
    fetchCatalogFilters(),
  ]);

  if (!page) {
    notFound();
  }

  const { category, breadcrumbs } = page;

  return (
    <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6">
      <nav aria-label="Breadcrumb" className="mb-4 text-sm text-muted-foreground">
        <Link href="/" className="hover:text-foreground">
          Home
        </Link>
        <span className="mx-2" aria-hidden="true">
          /
        </span>
        <Link href="/categories" className="hover:text-foreground">
          Categories
        </Link>
        {breadcrumbs.slice(0, -1).map((crumb) => (
          <span key={crumb.slug}>
            <span className="mx-2" aria-hidden="true">
              /
            </span>
            <Link href={`/categories/${crumb.slug}`} className="hover:text-foreground">
              {crumb.name}
            </Link>
          </span>
        ))}
        <span className="mx-2" aria-hidden="true">
          /
        </span>
        <span className="text-foreground">{category.name}</span>
      </nav>

      <header className="mb-6">
        <h1 className="text-3xl font-semibold tracking-tight">{category.name}</h1>

        {category.description ? (
          <p className="mt-2 max-w-2xl text-muted-foreground">{category.description}</p>
        ) : null}
      </header>

      {category.children && category.children.length > 0 ? (
        <nav aria-label="Subcategories" className="mb-6 flex flex-wrap gap-2">
          {category.children.map((child) => (
            <Link
              key={child.id}
              href={`/categories/${child.slug}`}
              className="rounded-full border border-border px-3 py-1 text-sm transition-colors hover:border-foreground"
            >
              {child.name}
            </Link>
          ))}
        </nav>
      ) : null}

      <div className="grid gap-8 lg:grid-cols-[16rem_1fr]">
        <aside aria-label="Filters" className="hidden lg:sticky lg:top-24 lg:block lg:self-start">
          <ProductFilter filters={filters} hideCategories />
        </aside>

        <div className="min-w-0">
          <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div className="lg:hidden">
              <ProductFilter filters={filters} hideCategories />
            </div>

            <CatalogSort sorts={filters.sorts} />
          </div>

          <Suspense key={filterKey(resolved)} fallback={<ProductGridSkeleton />}>
            <CategoryResults
              slug={slug}
              categoryName={category.name}
              searchParams={resolved}
              config={config}
            />
          </Suspense>
        </div>
      </div>
    </div>
  );
}

async function CategoryResults({
  slug,
  categoryName,
  searchParams,
  config,
}: {
  slug: string;
  categoryName: string;
  searchParams: Record<string, string | string[] | undefined>;
  config: StoreConfig;
}) {
  const page = await fetchCategoryPage(slug, toProductListParams(searchParams));

  if (!page) {
    // The category resolved a moment ago in the parent, so reaching here means
    // it was withdrawn mid-render. An empty grid is the honest outcome.
    return (
      <ProductGrid products={[]} config={config} emptyMessage="This category is unavailable." />
    );
  }

  return (
    <>
      {page.pagination ? (
        <p className="mb-4 text-sm text-muted-foreground">
          {page.pagination.total} product{page.pagination.total === 1 ? '' : 's'}
        </p>
      ) : null}

      <ProductGrid
        products={page.products}
        config={config}
        emptyMessage={`No products in ${categoryName} match these filters.`}
      />

      {page.pagination ? <CatalogPagination pagination={page.pagination} /> : null}
    </>
  );
}
