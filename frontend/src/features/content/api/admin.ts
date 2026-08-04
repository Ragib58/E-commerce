import { z } from 'zod';

import { apiClient } from '@/lib/api/client';
import { getToken } from '@/lib/api/auth-token';
import { resolveApiBaseUrl } from '@/lib/env';
import { ApiError } from '@/lib/api/errors';
import {
  adminBannerSchema,
  adminCmsPageSchema,
  adminSectionSchema,
  placementOptionSchema,
  sectionSchema,
  sectionTypeOptionSchema,
  type AdminBanner,
  type AdminCmsPage,
  type AdminSection,
  type PlacementOption,
  type Section,
  type SectionTypeOption,
} from '../types';

/**
 * Data access for the content admin panel.
 *
 * Separate from the public module because the concerns differ: every request
 * here is authenticated, none is cached (an operator must see their own write
 * immediately), and the responses carry the scheduling and status fields the
 * storefront never receives.
 *
 * Unlike the public module, these throw. A failed save must surface as an error
 * the operator can act on — silently returning an empty result would let them
 * believe a change was applied when it was not.
 */

const NO_CACHE = { cache: 'no-store' } as const;

/**
 * Submit multipart data.
 *
 * Uploads bypass `apiClient` because it JSON-encodes bodies and sets a JSON
 * content type; a FormData body needs the browser to set its own boundary.
 *
 * Always POST, with `_method` carrying the real verb: PHP does not populate
 * `$_POST` for a multipart PATCH body, so the fields would arrive empty. The
 * server's method-override middleware turns it back into a PATCH.
 */
async function submitFormData<T>(
  path: string,
  formData: FormData,
  method: 'POST' | 'PATCH' = 'POST',
): Promise<T> {
  if (method === 'PATCH') {
    formData.append('_method', 'PATCH');
  }

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

/**
 * Append a value to FormData with the encoding Laravel expects.
 *
 * Two conversions matter. Booleans become "1"/"0", because `String(false)` is
 * "false" — a non-empty string that PHP's boolean validation reads as true.
 * Null becomes an empty string, which is how the API distinguishes "clear this
 * field" from "leave it alone" (an omitted key).
 */
function appendField(formData: FormData, key: string, value: unknown): void {
  if (value === undefined) return;

  if (value === null) {
    formData.append(key, '');

    return;
  }

  if (typeof value === 'boolean') {
    formData.append(key, value ? '1' : '0');

    return;
  }

  if (value instanceof File) {
    formData.append(key, value);

    return;
  }

  formData.append(key, String(value));
}

/* -------------------------------------------------------------------------- */
/* Homepage sections                                                           */
/* -------------------------------------------------------------------------- */

export interface HomepageSections {
  sections: AdminSection[];
  /** The "add section" menu, served by the API rather than hardcoded here. */
  availableTypes: SectionTypeOption[];
}

export async function fetchHomepageSections(): Promise<HomepageSections> {
  const result = await apiClient.get<unknown>('/admin/homepage/sections', NO_CACHE);

  return {
    sections: z.array(adminSectionSchema).catch([]).parse(result.data),
    availableTypes: z
      .array(sectionTypeOptionSchema)
      .catch([])
      .parse(result.meta.available_types ?? []),
  };
}

/**
 * The homepage exactly as the storefront would receive it, optionally at a
 * chosen moment.
 *
 * `at` is what makes scheduling reviewable before the scheduled date arrives:
 * an operator can confirm a Black Friday section appears on the day without
 * waiting for the day.
 */
export async function fetchHomepagePreview(at?: string): Promise<Section[]> {
  const result = await apiClient.get<unknown>('/admin/homepage/preview', {
    ...NO_CACHE,
    params: at ? { at } : undefined,
  });

  return z.array(sectionSchema).catch([]).parse(result.data);
}

export interface SectionInput {
  type?: string;
  name?: string;
  heading?: string | null;
  subheading?: string | null;
  settings?: Record<string, unknown>;
  background_color?: string | null;
  container_width?: string | null;
  is_enabled?: boolean;
  starts_at?: string | null;
  ends_at?: string | null;
}

export async function createSection(input: SectionInput): Promise<AdminSection> {
  const result = await apiClient.post<unknown>('/admin/homepage/sections', {
    body: input,
    ...NO_CACHE,
  });

  return adminSectionSchema.parse(result.data);
}

export async function updateSection(id: number, input: SectionInput): Promise<AdminSection> {
  const result = await apiClient.patch<unknown>(`/admin/homepage/sections/${id}`, {
    body: input,
    ...NO_CACHE,
  });

  return adminSectionSchema.parse(result.data);
}

export async function deleteSection(id: number): Promise<void> {
  await apiClient.delete(`/admin/homepage/sections/${id}`, NO_CACHE);
}

export async function setSectionEnabled(id: number, isEnabled: boolean): Promise<AdminSection> {
  const result = await apiClient.patch<unknown>(`/admin/homepage/sections/${id}/status`, {
    body: { is_enabled: isEnabled },
    ...NO_CACHE,
  });

  return adminSectionSchema.parse(result.data);
}

/**
 * Persist a drag-and-drop rearrangement in one request.
 *
 * The whole ordering is sent rather than the single moved section: a drop moves
 * every section between the old and new positions, and sending them
 * individually would leave the page half-reordered if one call failed.
 */
export async function reorderSections(
  items: Array<{ id: number; sort_order: number }>,
): Promise<AdminSection[]> {
  const result = await apiClient.put<unknown>('/admin/homepage/sections/reorder', {
    body: { items },
    ...NO_CACHE,
  });

  return z.array(adminSectionSchema).catch([]).parse(result.data);
}

/* -------------------------------------------------------------------------- */
/* Banners                                                                     */
/* -------------------------------------------------------------------------- */

export interface AdminBanners {
  banners: AdminBanner[];
  placements: PlacementOption[];
}

export async function fetchAdminBanners(filters: {
  placement?: string;
  status?: string;
} = {}): Promise<AdminBanners> {
  const params: Record<string, string> = {};

  if (filters.placement) params.placement = filters.placement;
  if (filters.status) params.status = filters.status;

  const result = await apiClient.get<unknown>('/admin/banners', { ...NO_CACHE, params });

  return {
    banners: z.array(adminBannerSchema).catch([]).parse(result.data),
    placements: z.array(placementOptionSchema).catch([]).parse(result.meta.placements ?? []),
  };
}

export interface BannerInput {
  title?: string;
  subtitle?: string | null;
  image?: File | null;
  mobile_image?: File | null;
  alt_text?: string | null;
  link_url?: string | null;
  link_label?: string | null;
  link_external?: boolean;
  placement?: string;
  status?: string;
  starts_at?: string | null;
  ends_at?: string | null;
}

function bannerFormData(input: BannerInput): FormData {
  const formData = new FormData();

  for (const [key, value] of Object.entries(input)) {
    // A null image means "no change" here rather than "clear it": the primary
    // image is required, and the API leaves an absent key alone.
    if ((key === 'image' || key === 'mobile_image') && !(value instanceof File)) continue;

    appendField(formData, key, value);
  }

  return formData;
}

export async function createBanner(input: BannerInput): Promise<AdminBanner> {
  const data = await submitFormData<unknown>('/admin/banners', bannerFormData(input));

  return adminBannerSchema.parse(data);
}

export async function updateBanner(id: number, input: BannerInput): Promise<AdminBanner> {
  const data = await submitFormData<unknown>(
    `/admin/banners/${id}`,
    bannerFormData(input),
    'PATCH',
  );

  return adminBannerSchema.parse(data);
}

export async function deleteBanner(id: number): Promise<void> {
  await apiClient.delete(`/admin/banners/${id}`, NO_CACHE);
}

export async function reorderBanners(
  items: Array<{ id: number; sort_order: number }>,
): Promise<void> {
  await apiClient.put('/admin/banners/reorder', { body: { items }, ...NO_CACHE });
}

/* -------------------------------------------------------------------------- */
/* CMS pages                                                                   */
/* -------------------------------------------------------------------------- */

export async function fetchAdminPages(filters: {
  search?: string;
  status?: string;
} = {}): Promise<AdminCmsPage[]> {
  const params: Record<string, string> = {};

  if (filters.search) params.search = filters.search;
  if (filters.status) params.status = filters.status;

  const result = await apiClient.get<unknown>('/admin/pages', { ...NO_CACHE, params });

  return z.array(adminCmsPageSchema).catch([]).parse(result.data);
}

export async function fetchAdminPage(slug: string): Promise<AdminCmsPage> {
  const result = await apiClient.get<unknown>(
    `/admin/pages/${encodeURIComponent(slug)}`,
    NO_CACHE,
  );

  return adminCmsPageSchema.parse(result.data);
}

export interface CmsPageInput {
  title?: string;
  slug?: string;
  excerpt?: string | null;
  content?: string | null;
  featured_image?: File | null;
  og_image?: File | null;
  seo_title?: string | null;
  seo_description?: string | null;
  seo_keywords?: string | null;
  is_indexable?: boolean;
  status?: string;
  starts_at?: string | null;
  ends_at?: string | null;
}

/**
 * Whether this payload carries a file, and therefore needs multipart encoding.
 *
 * Most page saves are text only, and JSON is both cheaper and easier to debug —
 * so multipart is used only when there is actually a file to send.
 */
function hasFile(input: CmsPageInput): boolean {
  return input.featured_image instanceof File || input.og_image instanceof File;
}

function pageFormData(input: CmsPageInput): FormData {
  const formData = new FormData();

  for (const [key, value] of Object.entries(input)) {
    if ((key === 'featured_image' || key === 'og_image') && !(value instanceof File)) continue;

    appendField(formData, key, value);
  }

  return formData;
}

export async function createPage(input: CmsPageInput): Promise<AdminCmsPage> {
  if (hasFile(input)) {
    return adminCmsPageSchema.parse(
      await submitFormData<unknown>('/admin/pages', pageFormData(input)),
    );
  }

  const result = await apiClient.post<unknown>('/admin/pages', { body: input, ...NO_CACHE });

  return adminCmsPageSchema.parse(result.data);
}

export async function updatePage(slug: string, input: CmsPageInput): Promise<AdminCmsPage> {
  const path = `/admin/pages/${encodeURIComponent(slug)}`;

  if (hasFile(input)) {
    return adminCmsPageSchema.parse(
      await submitFormData<unknown>(path, pageFormData(input), 'PATCH'),
    );
  }

  const result = await apiClient.patch<unknown>(path, { body: input, ...NO_CACHE });

  return adminCmsPageSchema.parse(result.data);
}

export async function setPageStatus(slug: string, status: string): Promise<AdminCmsPage> {
  const result = await apiClient.patch<unknown>(
    `/admin/pages/${encodeURIComponent(slug)}/status`,
    { body: { status }, ...NO_CACHE },
  );

  return adminCmsPageSchema.parse(result.data);
}

export async function deletePage(slug: string): Promise<void> {
  await apiClient.delete(`/admin/pages/${encodeURIComponent(slug)}`, NO_CACHE);
}
