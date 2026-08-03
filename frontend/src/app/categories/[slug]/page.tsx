import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { fetchCatalogFilters, fetchCategoryPage } from '@/features/catalog/api';
import { ProductGrid } from '@/features/catalog/components/product-card';
import { CatalogPagination } from '@/features/catalog/components/catalog-pagination';
import { CatalogToolbar } from '@/features/catalog/components/catalog-toolbar';
import { getStoreConfig } from '@/features/settings/lib/get-store-config';
import type { ProductListParams } from '@/features/catalog/types';

/**
 * A single category's products.
 *
 * The listing includes everything filed in the category's descendants, which is
 * what a shopper expects: clicking "Clothing" should show the shirts under
 * "Clothing > Shirts", not an empty page.
 */

interface PageProps {
  params: Promise<{ slug: string }>;
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}

export async function generateMetadata({ params }: PageProps): Promise<Metadata> {
  const { slug } = await params;

  const [page, { config }] = await Promise.all([fetchCategoryPage(slug), getStoreConfig()]);

  if (!page) {
    return { title: 'Category not found' };
  }

  const title = page.category.seo?.meta_title ?? page.category.name;

  return {
    title: `${title} — ${config.companyName}`,
    description: page.category.seo?.meta_description ?? page.category.description ?? undefined,
    robots: { index: config.indexable, follow: config.indexable },
    openGraph: {
      title,
      description: page.category.description ?? undefined,
      images: page.category.banner ? [{ url: page.category.banner }] : undefined,
    },
  };
}

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
    sort: single('sort'),
    page: single('page') ? Number(single('page')) : undefined,
    brand: brand ? brand.split(',').filter(Boolean) : undefined,
    min_price: minPrice ? Number(minPrice) : undefined,
    max_price: maxPrice ? Number(maxPrice) : undefined,
    in_stock: single('in_stock') === '1',
    attributes: Object.keys(attributes).length > 0 ? attributes : undefined,
  };
}

export default async function CategoryPage({ params, searchParams }: PageProps) {
  const [{ slug }, resolvedSearch] = await Promise.all([params, searchParams]);

  const [page, { config }, filters] = await Promise.all([
    fetchCategoryPage(slug, toParams(resolvedSearch)),
    getStoreConfig(),
    fetchCatalogFilters(),
  ]);

  if (!page) {
    notFound();
  }

  const { category, products, breadcrumbs, pagination } = page;

  return (
    <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6">
      <nav aria-label="Breadcrumb" className="mb-4 text-sm text-muted-foreground">
        <Link href="/" className="hover:text-foreground">
          Home
        </Link>
        <span className="mx-2">/</span>
        <Link href="/categories" className="hover:text-foreground">
          Categories
        </Link>
        {breadcrumbs.slice(0, -1).map((crumb) => (
          <span key={crumb.slug}>
            <span className="mx-2">/</span>
            <Link href={`/categories/${crumb.slug}`} className="hover:text-foreground">
              {crumb.name}
            </Link>
          </span>
        ))}
        <span className="mx-2">/</span>
        <span className="text-foreground">{category.name}</span>
      </nav>

      <header className="mb-8">
        <h1 className="text-3xl font-semibold tracking-tight">{category.name}</h1>

        {category.description ? (
          <p className="mt-2 max-w-2xl text-muted-foreground">{category.description}</p>
        ) : null}

        {pagination ? (
          <p className="mt-1 text-sm text-muted-foreground">
            {pagination.total} product{pagination.total === 1 ? '' : 's'}
          </p>
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

      <CatalogToolbar filters={filters} sorts={filters.sorts} />

      <div className="mt-6">
        <ProductGrid
          products={products}
          config={config}
          emptyMessage={`No products in ${category.name} match your selection.`}
        />
      </div>

      {pagination ? <CatalogPagination pagination={pagination} /> : null}
    </div>
  );
}
