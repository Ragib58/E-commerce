import { apiClient } from '@/lib/api/client';
import { getToken } from '@/lib/api/auth-token';
import { resolveApiBaseUrl } from '@/lib/env';
import { ApiError } from '@/lib/api/errors';
import type { ApiPagination } from '@/lib/api/types';
import { z } from 'zod';
import {
  attributeSchema,
  brandSchema,
  categorySchema,
  inventorySummarySchema,
  productSchema,
  productVariantSchema,
  stockMovementSchema,
  type Attribute,
  type Brand,
  type Category,
  type InventorySummary,
  type Product,
  type ProductVariant,
  type StockMovement,
} from '../types';

/**
 * Data access for the admin catalog panel.
 *
 * Separate from the public module because the concerns differ: every request
 * here is authenticated, none is cached (an admin must see their own write
 * immediately), and the responses carry privileged fields the storefront never
 * receives.
 */

const NO_CACHE = { cache: 'no-store' } as const;

export interface Paginated<T> {
  items: T[];
  pagination: ApiPagination | null;
}

/**
 * Drop empty filter values before they reach the query string.
 *
 * An empty `search=` would otherwise be sent and, on some endpoints, filter
 * against the empty string rather than being ignored.
 */
function cleanParams(
  filters: Record<string, unknown>,
): Record<string, string | number | boolean> {
  const params: Record<string, string | number | boolean> = {};

  for (const [key, value] of Object.entries(filters)) {
    if (value === undefined || value === null || value === '') continue;

    params[key] = value as string | number | boolean;
  }

  return params;
}

/**
 * Upload multipart form data.
 *
 * The shared apiClient always serialises its body as JSON, which cannot carry a
 * file. This mirrors its auth and envelope handling for the few endpoints that
 * take an upload — deliberately narrow rather than complicating the client for
 * every caller.
 *
 * Content-Type is left unset on purpose: the browser must add it itself so the
 * multipart boundary matches the body it generated.
 */
async function postFormData<T>(path: string, formData: FormData): Promise<T> {
  const token = getToken('admin');

  const response = await fetch(`${resolveApiBaseUrl()}${path}`, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: formData,
  });

  const payload = (await response.json()) as
    | { success: true; data: T }
    | { success: false; message: string; errors?: Record<string, string[]> };

  if (!response.ok || payload.success === false) {
    throw ApiError.fromResponse(
      payload.success === false ? payload : null,
      response.status,
      response.headers.get('X-Request-Id') ?? undefined,
    );
  }

  return payload.data;
}

/* -------------------------------------------------------------------------- */
/* Products                                                                    */
/* -------------------------------------------------------------------------- */

export async function fetchAdminProducts(
  filters: Record<string, unknown> = {},
): Promise<Paginated<Product>> {
  const result = await apiClient.get<unknown>('/admin/products', {
    params: cleanParams(filters),
    ...NO_CACHE,
  });

  return {
    items: z.array(productSchema).parse(result.data),
    pagination: result.meta.pagination ?? null,
  };
}

export async function fetchAdminProduct(id: string): Promise<Product> {
  const result = await apiClient.get<unknown>(`/admin/products/${id}`, NO_CACHE);

  return productSchema.parse(result.data);
}

export async function createProduct(body: Record<string, unknown>): Promise<Product> {
  const result = await apiClient.post<unknown>('/admin/products', { body });

  return productSchema.parse(result.data);
}

export async function updateProduct(
  id: string,
  body: Record<string, unknown>,
): Promise<Product> {
  const result = await apiClient.patch<unknown>(`/admin/products/${id}`, { body });

  return productSchema.parse(result.data);
}

export async function deleteProduct(id: string): Promise<void> {
  await apiClient.delete(`/admin/products/${id}`);
}

export async function setProductStatus(id: string, status: string): Promise<Product> {
  const result = await apiClient.patch<unknown>(`/admin/products/${id}/status`, {
    body: { status },
  });

  return productSchema.parse(result.data);
}

/**
 * Apply one action to a selection from the table.
 */
export async function bulkProductAction(
  ids: string[],
  action: 'publish' | 'draft' | 'archive' | 'feature' | 'unfeature' | 'delete',
): Promise<number> {
  const result = await apiClient.post<{ affected: number }>('/admin/products/bulk', {
    body: { ids, action },
  });

  return result.data.affected;
}

export async function uploadProductImage(
  productId: string,
  file: File,
  options: { altText?: string; isThumbnail?: boolean } = {},
): Promise<unknown> {
  const formData = new FormData();
  formData.append('image', file);

  if (options.altText) formData.append('alt_text', options.altText);
  if (options.isThumbnail) formData.append('is_thumbnail', '1');

  return postFormData(`/admin/products/${productId}/media`, formData);
}

export async function deleteProductImage(productId: string, mediaId: number): Promise<void> {
  await apiClient.delete(`/admin/products/${productId}/media/${mediaId}`);
}

export async function setProductThumbnail(productId: string, mediaId: number): Promise<void> {
  await apiClient.patch(`/admin/products/${productId}/media/${mediaId}/thumbnail`);
}

/* -------------------------------------------------------------------------- */
/* Variants                                                                    */
/* -------------------------------------------------------------------------- */

export async function fetchVariants(productId: string): Promise<ProductVariant[]> {
  const result = await apiClient.get<unknown>(
    `/admin/products/${productId}/variants`,
    NO_CACHE,
  );

  return z.array(productVariantSchema).parse(result.data);
}

export async function createVariant(
  productId: string,
  body: Record<string, unknown>,
): Promise<ProductVariant> {
  const result = await apiClient.post<unknown>(`/admin/products/${productId}/variants`, {
    body,
  });

  return productVariantSchema.parse(result.data);
}

export async function updateVariant(
  variantId: string,
  body: Record<string, unknown>,
): Promise<ProductVariant> {
  const result = await apiClient.patch<unknown>(`/admin/variants/${variantId}`, { body });

  return productVariantSchema.parse(result.data);
}

export async function deleteVariant(variantId: string): Promise<void> {
  await apiClient.delete(`/admin/variants/${variantId}`);
}

/**
 * Build the whole option matrix at once.
 *
 * @param attributeValueGroups Value ids grouped by attribute: [[1,2,3], [7,8]].
 */
export async function generateVariantMatrix(
  productId: string,
  attributeValueGroups: number[][],
  defaults: Record<string, unknown> = {},
): Promise<ProductVariant[]> {
  const result = await apiClient.post<unknown>(
    `/admin/products/${productId}/variants/generate`,
    { body: { attributes: attributeValueGroups, defaults } },
  );

  return z.array(productVariantSchema).parse(result.data);
}

/* -------------------------------------------------------------------------- */
/* Categories                                                                  */
/* -------------------------------------------------------------------------- */

export async function fetchAdminCategories(
  filters: Record<string, unknown> = {},
): Promise<Paginated<Category>> {
  const result = await apiClient.get<unknown>('/admin/categories', {
    params: cleanParams(filters),
    ...NO_CACHE,
  });

  return {
    items: z.array(categorySchema).parse(result.data),
    pagination: result.meta.pagination ?? null,
  };
}

/**
 * The full tree, for the manager view and the parent picker.
 */
export async function fetchCategoryTree(): Promise<Category[]> {
  const result = await apiClient.get<unknown>('/admin/categories', {
    params: { tree: 1 },
    ...NO_CACHE,
  });

  return z.array(categorySchema).parse(result.data);
}

export async function createCategory(body: Record<string, unknown>): Promise<Category> {
  const result = await apiClient.post<unknown>('/admin/categories', { body });

  return categorySchema.parse(result.data);
}

export async function updateCategory(
  id: number,
  body: Record<string, unknown>,
): Promise<Category> {
  const result = await apiClient.patch<unknown>(`/admin/categories/${id}`, { body });

  return categorySchema.parse(result.data);
}

/**
 * @param cascade Re-home children and uncategorise products instead of refusing.
 */
export async function deleteCategory(id: number, cascade = false): Promise<void> {
  await apiClient.delete(`/admin/categories/${id}${cascade ? '?cascade=1' : ''}`);
}

export async function setCategoryStatus(id: number, status: string): Promise<Category> {
  const result = await apiClient.patch<unknown>(`/admin/categories/${id}/status`, {
    body: { status },
  });

  return categorySchema.parse(result.data);
}

/* -------------------------------------------------------------------------- */
/* Brands                                                                      */
/* -------------------------------------------------------------------------- */

export async function fetchAdminBrands(
  filters: Record<string, unknown> = {},
): Promise<Paginated<Brand>> {
  const result = await apiClient.get<unknown>('/admin/brands', {
    params: cleanParams(filters),
    ...NO_CACHE,
  });

  return {
    items: z.array(brandSchema).parse(result.data),
    pagination: result.meta.pagination ?? null,
  };
}

export async function createBrand(body: Record<string, unknown>): Promise<Brand> {
  const result = await apiClient.post<unknown>('/admin/brands', { body });

  return brandSchema.parse(result.data);
}

export async function updateBrand(id: number, body: Record<string, unknown>): Promise<Brand> {
  const result = await apiClient.patch<unknown>(`/admin/brands/${id}`, { body });

  return brandSchema.parse(result.data);
}

export async function deleteBrand(id: number, cascade = false): Promise<void> {
  await apiClient.delete(`/admin/brands/${id}${cascade ? '?cascade=1' : ''}`);
}

export async function setBrandStatus(id: number, status: string): Promise<Brand> {
  const result = await apiClient.patch<unknown>(`/admin/brands/${id}/status`, {
    body: { status },
  });

  return brandSchema.parse(result.data);
}

/* -------------------------------------------------------------------------- */
/* Attributes                                                                  */
/* -------------------------------------------------------------------------- */

export async function fetchAttributes(): Promise<Attribute[]> {
  const result = await apiClient.get<unknown>('/admin/attributes', NO_CACHE);

  return z.array(attributeSchema).parse(result.data);
}

export async function createAttribute(body: Record<string, unknown>): Promise<Attribute> {
  const result = await apiClient.post<unknown>('/admin/attributes', { body });

  return attributeSchema.parse(result.data);
}

/* -------------------------------------------------------------------------- */
/* Inventory                                                                   */
/* -------------------------------------------------------------------------- */

export interface StockAdjustment {
  /** `delta` applies a signed change; `absolute` sets a counted figure. */
  mode: 'delta' | 'absolute';
  quantity: number;
  reason: string;
  variantId?: string | null;
  note?: string;
}

export async function adjustStock(
  productId: string,
  adjustment: StockAdjustment,
): Promise<{ stock: number; movement: StockMovement }> {
  const result = await apiClient.post<{ stock: number; movement: unknown }>(
    `/admin/products/${productId}/stock`,
    {
      body: {
        mode: adjustment.mode,
        quantity: adjustment.quantity,
        reason: adjustment.reason,
        variant_id: adjustment.variantId ?? null,
        note: adjustment.note ?? null,
      },
    },
  );

  return {
    stock: result.data.stock,
    movement: stockMovementSchema.parse(result.data.movement),
  };
}

export async function fetchStockHistory(
  productId: string,
  filters: Record<string, unknown> = {},
): Promise<Paginated<StockMovement>> {
  const result = await apiClient.get<unknown>(`/admin/products/${productId}/stock/history`, {
    params: cleanParams(filters),
    ...NO_CACHE,
  });

  return {
    items: z.array(stockMovementSchema).parse(result.data),
    pagination: result.meta.pagination ?? null,
  };
}

export async function fetchInventoryMovements(
  filters: Record<string, unknown> = {},
): Promise<Paginated<StockMovement>> {
  const result = await apiClient.get<unknown>('/admin/inventory/movements', {
    params: cleanParams(filters),
    ...NO_CACHE,
  });

  return {
    items: z.array(stockMovementSchema).parse(result.data),
    pagination: result.meta.pagination ?? null,
  };
}

export interface InventoryAlerts {
  lowStockProducts: Product[];
  lowStockVariants: ProductVariant[];
  outOfStockProducts: Product[];
}

export async function fetchInventoryAlerts(): Promise<InventoryAlerts> {
  const result = await apiClient.get<{
    low_stock_products: unknown;
    low_stock_variants: unknown;
    out_of_stock_products: unknown;
  }>('/admin/inventory/alerts', NO_CACHE);

  return {
    lowStockProducts: z.array(productSchema).catch([]).parse(result.data.low_stock_products),
    lowStockVariants: z
      .array(productVariantSchema)
      .catch([])
      .parse(result.data.low_stock_variants),
    outOfStockProducts: z
      .array(productSchema)
      .catch([])
      .parse(result.data.out_of_stock_products),
  };
}

export async function fetchInventorySummary(): Promise<InventorySummary> {
  const result = await apiClient.get<unknown>('/admin/inventory/summary', NO_CACHE);

  return inventorySummarySchema.parse(result.data);
}
