import { z } from 'zod';

import { productSchema } from '@/features/catalog/types';

/**
 * Schemas for the storefront content API — the dynamic homepage, banners, and
 * CMS pages.
 *
 * Parsed at the boundary rather than trusted, for the same reason as the
 * catalog schemas: the Laravel API is a separate deployable that can change
 * independently, and a silently-renamed field would otherwise surface as a
 * blank homepage far from its cause.
 *
 * One rule shapes every schema here: **an unrecognised section type must not
 * break the page.** The backend can ship a new section type before the frontend
 * knows how to render it, and when that happens the section is skipped and the
 * rest of the homepage renders normally. That is why `type` is a plain string
 * rather than a strict enum — see `sectionSchema`.
 */

export const SECTION_TYPES = [
  'hero_slider',
  'promo_banner',
  'featured_products',
  'new_arrivals',
  'best_sellers',
  'categories',
  'flash_sale',
  'product_collection',
  'testimonials',
  'blog_posts',
  'custom_content',
] as const;

export type SectionType = (typeof SECTION_TYPES)[number];

export function isKnownSectionType(value: string): value is SectionType {
  return (SECTION_TYPES as readonly string[]).includes(value);
}

export const publishStatusSchema = z.enum(['draft', 'published', 'scheduled', 'archived']);

export type PublishStatus = z.infer<typeof publishStatusSchema>;

export const bannerPlacementSchema = z.enum([
  'hero_slider',
  'homepage_promo',
  'category_top',
  'sidebar',
  'checkout',
  'popup',
]);

export type BannerPlacement = z.infer<typeof bannerPlacementSchema>;

/**
 * A banner, as the storefront receives it.
 *
 * Status and scheduling fields are absent from the public payload by design:
 * the API only ever returns banners that are already live, so there is nothing
 * for the client to filter. They appear on the admin schema below.
 */
export const bannerSchema = z.object({
  id: z.number(),
  title: z.string(),
  subtitle: z.string().nullish(),
  image: z.string().nullish(),
  /** Falls back to `image` server-side, so a <source> can always be set. */
  mobile_image: z.string().nullish(),
  alt_text: z.string().default(''),
  link_url: z.string().nullish(),
  link_label: z.string().nullish(),
  link_external: z.boolean().default(false),
  placement: bannerPlacementSchema.catch('homepage_promo'),
  sort_order: z.number().default(0),
});

export type Banner = z.infer<typeof bannerSchema>;

/** A category as a homepage grid tile — a subset of the catalog category. */
export const sectionCategorySchema = z.object({
  id: z.number(),
  name: z.string(),
  slug: z.string(),
  image: z.string().nullish(),
  description: z.string().nullish(),
  products_count: z.number().nullish(),
});

export type SectionCategory = z.infer<typeof sectionCategorySchema>;

export const testimonialSchema = z.object({
  quote: z.string(),
  author: z.string().nullish(),
  role: z.string().nullish(),
  avatar: z.string().nullish(),
  rating: z.number().nullish(),
});

export type Testimonial = z.infer<typeof testimonialSchema>;

/**
 * Section settings.
 *
 * Deliberately permissive — a passthrough record rather than a discriminated
 * union over eleven exact shapes. Two reasons:
 *
 *   - The backend merges each type's defaults in before responding, so a
 *     setting introduced after a section was last saved still arrives.
 *   - A strict union would reject the whole homepage when the backend adds one
 *     new setting key, which is precisely the coupling this module exists to
 *     avoid.
 *
 * Individual renderers read the keys they need through the typed helpers in
 * ../lib/settings, which apply per-key fallbacks.
 */
export const sectionSettingsSchema = z.record(z.unknown()).default({});

export const sectionStyleSchema = z.object({
  background_color: z.string().nullish(),
  container_width: z.string().default('default'),
});

/**
 * A rendered homepage section.
 *
 * `items` is a heterogeneous array whose element type depends on `type`;
 * narrowing happens in the renderer, which parses the array with the schema
 * appropriate to the section it is about to draw. Parsing it eagerly here would
 * require the discriminated union this schema deliberately avoids.
 */
export const sectionSchema = z.object({
  id: z.number(),
  /**
   * A plain string, not an enum.
   *
   * `z.enum(SECTION_TYPES)` here would fail the whole homepage the first time
   * the backend shipped a section type this build does not know about. Instead
   * the value is carried through and the renderer skips what it cannot draw.
   */
  type: z.string(),
  name: z.string(),
  heading: z.string().nullish(),
  subheading: z.string().nullish(),
  settings: sectionSettingsSchema,
  style: sectionStyleSchema.catch({ background_color: null, container_width: 'default' }),
  sort_order: z.number().default(0),
  starts_at: z.string().nullish(),
  ends_at: z.string().nullish(),
  items: z.array(z.unknown()).default([]),
  has_content: z.boolean().default(true),
});

export type Section = z.infer<typeof sectionSchema>;

/** Products arrive in the same shape as everywhere else in the app. */
export const sectionProductsSchema = z.array(productSchema).catch([]);
export const sectionBannersSchema = z.array(bannerSchema).catch([]);
export const sectionCategoriesSchema = z.array(sectionCategorySchema).catch([]);
export const sectionTestimonialsSchema = z.array(testimonialSchema).catch([]);

export const cmsPageSeoSchema = z.object({
  title: z.string().nullish(),
  description: z.string().nullish(),
  keywords: z.string().nullish(),
  og_image: z.string().nullish(),
  indexable: z.boolean().default(true),
});

/**
 * A CMS page.
 *
 * `content` is absent from index responses — a footer needs titles and slugs,
 * not six full policy documents — hence `nullish` rather than required.
 */
export const cmsPageSchema = z.object({
  id: z.number(),
  title: z.string(),
  slug: z.string(),
  excerpt: z.string().nullish(),
  content: z.string().nullish(),
  featured_image: z.string().nullish(),
  seo: cmsPageSeoSchema.nullish(),
  sort_order: z.number().default(0),
  published_at: z.string().nullish(),
  updated_at: z.string().nullish(),
});

export type CmsPage = z.infer<typeof cmsPageSchema>;

/*
|------------------------------------------------------------------------------
| Admin shapes
|------------------------------------------------------------------------------
|
| The admin panel sees configuration where the storefront sees content: a
| section's `product_ids` rather than the products, and the scheduling fields
| the public payload omits.
*/

/** Where a record sits relative to its schedule, computed server-side. */
export const windowStateSchema = z.enum(['live', 'scheduled', 'expired']);

export type WindowState = z.infer<typeof windowStateSchema>;

export const adminSectionSchema = z.object({
  id: z.number(),
  type: z.string(),
  type_label: z.string(),
  name: z.string(),
  heading: z.string().nullish(),
  subheading: z.string().nullish(),
  settings: sectionSettingsSchema,
  style: sectionStyleSchema.catch({ background_color: null, container_width: 'default' }),
  is_enabled: z.boolean(),
  sort_order: z.number().default(0),
  starts_at: z.string().nullish(),
  ends_at: z.string().nullish(),
  window_state: windowStateSchema.catch('live'),
  /**
   * Enabled AND inside its window.
   *
   * Distinct from `is_enabled`, and the distinction matters in the UI: a
   * section can be switched on and still invisible because its start date has
   * not arrived. Computed server-side so the panel and the storefront cannot
   * disagree about it.
   */
  is_live: z.boolean().default(true),
  updated_at: z.string().nullish(),
});

export type AdminSection = z.infer<typeof adminSectionSchema>;

/** One entry of the "add section" menu, served by the API. */
export const sectionTypeOptionSchema = z.object({
  value: z.string(),
  label: z.string(),
  description: z.string().default(''),
  allows_multiple: z.boolean().default(false),
  default_settings: z.record(z.unknown()).default({}),
});

export type SectionTypeOption = z.infer<typeof sectionTypeOptionSchema>;

export const adminBannerSchema = bannerSchema.extend({
  status: publishStatusSchema.catch('draft'),
  starts_at: z.string().nullish(),
  ends_at: z.string().nullish(),
  window_state: windowStateSchema.catch('live'),
  is_live: z.boolean().default(false),
  updated_at: z.string().nullish(),
});

export type AdminBanner = z.infer<typeof adminBannerSchema>;

export const adminCmsPageSchema = cmsPageSchema.extend({
  status: publishStatusSchema.catch('draft'),
  /** Marks the seeded legal pages, which may be edited but not deleted. */
  is_system: z.boolean().default(false),
  starts_at: z.string().nullish(),
  ends_at: z.string().nullish(),
  window_state: windowStateSchema.optional(),
  created_at: z.string().nullish(),
});

export type AdminCmsPage = z.infer<typeof adminCmsPageSchema>;

export const placementOptionSchema = z.object({
  value: z.string(),
  label: z.string(),
});

export type PlacementOption = z.infer<typeof placementOptionSchema>;
