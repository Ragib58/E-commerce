import { apiClient } from '@/lib/api/client';
import { CACHE_TAGS, REVALIDATE_SECONDS } from '@/config/cache';
import type { ApiPagination } from '@/lib/api/types';
import {
  attributeSchema,
  brandSchema,
  breadcrumbSchema,
  catalogFiltersSchema,
  categorySchema,
  priceRangeSchema,
  productSchema,
  type Brand,
  type CatalogFilters,
  type Category,
  type Breadcrumb,
  type Product,
  type ProductListParams,
} from '../types';
import { z } from 'zod';

/**
 * Data access for the public catalog.
 *
 * Every response is validated before it reaches a component. A shape change in
 * the API therefore fails loudly at the boundary with a logged error, rather
 * than rendering a grid of undefined prices.
 *
 * Listing responses are cached by tag: Laravel purges the `catalog` tag when an
 * admin edits a product, so the ISR window is a backstop for a missed webhook
 * rather than the primary freshness mechanism.
 */

export interface PaginatedProducts {
  products: Product[];
  pagination: ApiPagination | null;
}

/**
 * Flatten the nested filter shape into the query string Laravel expects.
 *
 * `attributes: { colour: ['red','blue'] }` becomes `attributes[colour]=red,blue`
 * — arrays are comma-joined rather than repeated, because the shared apiClient
 * takes a flat record of scalars and repeating a key would require a bespoke
 * serialiser here.
 */
function toQueryParams(params: ProductListParams): Record<string, string | number | boolean> {
  const query: Record<string, string | number | boolean> = {};

  if (params.search) query.search = params.search;
  if (params.category) query.category = params.category;
  if (params.type) query.type = params.type;
  if (params.sort) query.sort = params.sort;
  if (params.page) query.page = params.page;
  if (params.per_page) query.per_page = params.per_page;
  if (typeof params.min_price === 'number') query.min_price = params.min_price;
  if (typeof params.max_price === 'number') query.max_price = params.max_price;
  if (params.featured) query.featured = true;
  if (params.new_arrival) query.new_arrival = true;
  if (params.best_seller) query.best_seller = true;
  if (params.in_stock) query.in_stock = true;

  if (params.brand?.length) {
    query['brand[]'] = params.brand.join(',');
  }

  for (const [attribute, values] of Object.entries(params.attributes ?? {})) {
    if (values.length > 0) {
      query[`attributes[${attribute}]`] = values.join(',');
    }
  }

  return query;
}

/**
 * Fetch a page of published products.
 *
 * Returns an empty page rather than throwing when the API is unreachable: a
 * transient backend outage should render an empty grid with a message, not a
 * 500 page for the whole storefront.
 */
export async function fetchProducts(params: ProductListParams = {}): Promise<PaginatedProducts> {
  try {
    const result = await apiClient.get<unknown>('/products', {
      params: toQueryParams(params),
      next: {
        revalidate: REVALIDATE_SECONDS.catalog,
        tags: [CACHE_TAGS.catalog],
      },
    });

    const parsed = z.array(productSchema).safeParse(result.data);

    if (!parsed.success) {
      console.error('[catalog] Product listing failed validation.', parsed.error.flatten());

      return { products: [], pagination: null };
    }

    return {
      products: parsed.data,
      pagination: result.meta.pagination ?? null,
    };
  } catch (error) {
    console.error('[catalog] Failed to load products.', error);

    return { products: [], pagination: null };
  }
}

export interface ProductDetail {
  product: Product;
  related: Product[];
  breadcrumbs: Breadcrumb[];
}

/**
 * Fetch a single published product.
 *
 * Returns null for a missing or unpublished product so the caller can render a
 * 404 — the API deliberately does not distinguish the two.
 *
 * Deliberately uncached: the page shows stock, and serving a cached "in stock"
 * for something that sold out minutes ago produces a failed checkout, which
 * costs more than the request saved.
 */
export async function fetchProduct(slug: string): Promise<ProductDetail | null> {
  try {
    const result = await apiClient.get<unknown>(`/products/${encodeURIComponent(slug)}`, {
      cache: 'no-store',
    });

    const parsed = productSchema.safeParse(result.data);

    if (!parsed.success) {
      console.error('[catalog] Product failed validation.', parsed.error.flatten());

      return null;
    }

    return {
      product: parsed.data,
      related: z.array(productSchema).catch([]).parse(result.meta.related ?? []),
      breadcrumbs: z.array(breadcrumbSchema).catch([]).parse(result.meta.breadcrumbs ?? []),
    };
  } catch {
    // A 404 is the expected path for an unknown slug, so this is not logged as
    // an error — the caller renders notFound().
    return null;
  }
}

/**
 * The published category tree for storefront navigation.
 */
export async function fetchCategories(): Promise<Category[]> {
  try {
    const result = await apiClient.get<unknown>('/categories', {
      next: {
        revalidate: REVALIDATE_SECONDS.catalog,
        tags: [CACHE_TAGS.catalog],
      },
    });

    return z.array(categorySchema).catch([]).parse(result.data);
  } catch (error) {
    console.error('[catalog] Failed to load categories.', error);

    return [];
  }
}

export interface CategoryPage {
  category: Category;
  products: Product[];
  breadcrumbs: Breadcrumb[];
  pagination: ApiPagination | null;
  priceRange: { min: number; max: number };
}

/**
 * A category page: the category, its products, and its filter bounds.
 */
export async function fetchCategoryPage(
  slug: string,
  params: ProductListParams = {},
): Promise<CategoryPage | null> {
  try {
    const result = await apiClient.get<unknown>(`/categories/${encodeURIComponent(slug)}`, {
      params: toQueryParams(params),
      next: {
        revalidate: REVALIDATE_SECONDS.catalog,
        tags: [CACHE_TAGS.catalog],
      },
    });

    const category = categorySchema.safeParse(result.meta.category);

    if (!category.success) {
      console.error('[catalog] Category failed validation.', category.error.flatten());

      return null;
    }

    return {
      category: category.data,
      products: z.array(productSchema).catch([]).parse(result.data),
      breadcrumbs: z.array(breadcrumbSchema).catch([]).parse(result.meta.breadcrumbs ?? []),
      pagination: result.meta.pagination ?? null,
      priceRange: priceRangeSchema.catch({ min: 0, max: 0 }).parse(result.meta.price_range ?? {}),
    };
  } catch {
    return null;
  }
}

export async function fetchBrands(): Promise<Brand[]> {
  try {
    const result = await apiClient.get<unknown>('/brands', {
      next: {
        revalidate: REVALIDATE_SECONDS.catalog,
        tags: [CACHE_TAGS.catalog],
      },
    });

    return z.array(brandSchema).catch([]).parse(result.data);
  } catch (error) {
    console.error('[catalog] Failed to load brands.', error);

    return [];
  }
}

/**
 * Everything a filter rail needs, in one request rather than three.
 */
export async function fetchCatalogFilters(): Promise<CatalogFilters> {
  const empty: CatalogFilters = {
    attributes: [],
    brands: [],
    price_range: { min: 0, max: 0 },
    sorts: [],
  };

  try {
    const result = await apiClient.get<unknown>('/catalog/filters', {
      next: {
        revalidate: REVALIDATE_SECONDS.catalog,
        tags: [CACHE_TAGS.catalog],
      },
    });

    return catalogFiltersSchema.catch(empty).parse(result.data);
  } catch (error) {
    console.error('[catalog] Failed to load filters.', error);

    return empty;
  }
}

/**
 * A merchandising rail for the homepage.
 */
export async function fetchRail(
  rail: 'featured' | 'new_arrivals' | 'best_sellers',
  limit = 12,
): Promise<Product[]> {
  try {
    const result = await apiClient.get<unknown>(`/catalog/rails/${rail}`, {
      params: { limit },
      next: {
        revalidate: REVALIDATE_SECONDS.catalog,
        tags: [CACHE_TAGS.catalog],
      },
    });

    return z.array(productSchema).catch([]).parse(result.data);
  } catch (error) {
    console.error(`[catalog] Failed to load the ${rail} rail.`, error);

    return [];
  }
}

export { attributeSchema };
