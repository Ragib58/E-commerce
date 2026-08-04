import type { ProductListParams, ProductType } from '../types';

/**
 * Translate a page's query string into API parameters.
 *
 * Shared by the shop, category, search, and brand pages so all four interpret
 * `?brand=a,b&attr_colour=red` identically. Four independent parsers is how one
 * page ends up supporting a filter the others silently ignore.
 *
 * The URL is the source of truth for filter state — it survives a refresh, can
 * be shared, and lets the back button undo a filter — so this function is the
 * single place that shape is understood.
 */

type SearchParamsInput = Record<string, string | string[] | undefined>;

/**
 * Next.js gives a repeated key as an array and a single one as a string.
 * Everything here wants one value, so the first wins.
 */
function single(params: SearchParamsInput, key: string): string | undefined {
  const value = params[key];

  return Array.isArray(value) ? value[0] : value;
}

/** Comma-separated multi-select facets: `?brand=nike,adidas`. */
function list(params: SearchParamsInput, key: string): string[] | undefined {
  const value = single(params, key);

  if (!value) return undefined;

  const items = value.split(',').filter(Boolean);

  return items.length > 0 ? items : undefined;
}

/**
 * A positive integer, or undefined.
 *
 * Guards against `?page=abc` and `?page=-1`, both of which would otherwise
 * reach the API as NaN or a negative offset.
 */
function positiveInt(params: SearchParamsInput, key: string): number | undefined {
  const value = single(params, key);

  if (!value) return undefined;

  const parsed = Number(value);

  return Number.isInteger(parsed) && parsed > 0 ? parsed : undefined;
}

const PRODUCT_TYPES = ['simple', 'variable', 'digital', 'customizable'] as const;

/**
 * The product type, checked against the known set.
 *
 * An arbitrary `?type=` would otherwise be forwarded to the API, which would
 * reject it — turning a nonsense URL into an error page rather than an
 * unfiltered listing. Ignoring an unrecognised value degrades better.
 */
function productType(params: SearchParamsInput): ProductType | undefined {
  const value = single(params, 'type');

  return PRODUCT_TYPES.includes(value as ProductType) ? (value as ProductType) : undefined;
}

/** A non-negative integer price bound, in minor units. */
function priceBound(params: SearchParamsInput, key: string): number | undefined {
  const value = single(params, key);

  if (!value) return undefined;

  const parsed = Number(value);

  return Number.isFinite(parsed) && parsed >= 0 ? Math.round(parsed) : undefined;
}

export function toProductListParams(params: SearchParamsInput): ProductListParams {
  /*
   * Attribute facets arrive as `attr_<slug>=red,blue` and are reassembled into
   * the nested shape the API client flattens back out. The prefix is what keeps
   * a dynamic attribute — one an operator added last week — from colliding with
   * a reserved parameter like `sort` or `page`.
   */
  const attributes: Record<string, string[]> = {};

  for (const [key, value] of Object.entries(params)) {
    const slug = key.match(/^attr_(.+)$/)?.[1];

    if (!slug || !value) continue;

    const values = (Array.isArray(value) ? value : value.split(',')).filter(Boolean);

    if (values.length > 0) attributes[slug] = values;
  }

  return {
    search: single(params, 'search'),
    category: single(params, 'category'),
    brand: list(params, 'brand'),
    type: productType(params),
    sort: single(params, 'sort'),
    page: positiveInt(params, 'page'),
    min_price: priceBound(params, 'min_price'),
    max_price: priceBound(params, 'max_price'),
    // Presence of the flag is the signal; any other value means "not filtered".
    in_stock: single(params, 'in_stock') === '1' ? true : undefined,
    featured: single(params, 'featured') === '1' ? true : undefined,
    new_arrival: single(params, 'new_arrival') === '1' ? true : undefined,
    best_seller: single(params, 'best_seller') === '1' ? true : undefined,
    attributes: Object.keys(attributes).length > 0 ? attributes : undefined,
  };
}

/**
 * A stable key describing the current filter state.
 *
 * Used as a React `key` on Suspense boundaries so changing a filter remounts
 * the boundary and shows its fallback, rather than holding the previous
 * results while the new ones load with no indication anything is happening.
 */
export function filterKey(params: SearchParamsInput): string {
  return Object.entries(params)
    .filter(([, value]) => value !== undefined && value !== '')
    .map(([key, value]) => `${key}=${Array.isArray(value) ? value.join(',') : value}`)
    .sort()
    .join('&');
}
