import { z } from 'zod';

/**
 * Schemas for the public settings payload.
 *
 * Validated at the boundary rather than trusted: the API is a separate
 * deployable that can change independently, and a silently-missing
 * `primary_color` would otherwise surface as an unstyled page far from its
 * cause. Every field is optional with a fallback applied downstream, so an
 * administrator who has not configured a value never breaks the render.
 */

export const generalSettingsSchema = z.object({
  company_name: z.string().nullish(),
  tagline: z.string().nullish(),
  description: z.string().nullish(),
  locale: z.string().nullish(),
  maintenance_mode: z.boolean().nullish(),
});

export const brandingSettingsSchema = z.object({
  logo: z.string().nullish(),
  /** Shown on light surfaces; falls back to `logo` when unset. */
  logo_light: z.string().nullish(),
  /** Shown when the visitor prefers a dark colour scheme. */
  logo_dark: z.string().nullish(),
  favicon: z.string().nullish(),
  og_image: z.string().nullish(),
  brand_description: z.string().nullish(),
});

export const themeSettingsSchema = z.object({
  primary_color: z.string().nullish(),
  secondary_color: z.string().nullish(),
  accent_color: z.string().nullish(),
  background_color: z.string().nullish(),
  foreground_color: z.string().nullish(),
  /** Falls back to `primary_color` when unset. */
  button_color: z.string().nullish(),
  destructive_color: z.string().nullish(),
  radius: z.string().nullish(),
  font_family: z.string().nullish(),
});

export const contactSettingsSchema = z.object({
  email: z.string().nullish(),
  phone: z.string().nullish(),
  address: z.string().nullish(),
  google_maps_url: z.string().nullish(),
  support_hours: z.string().nullish(),
});

export const socialSettingsSchema = z.object({
  facebook: z.string().nullish(),
  instagram: z.string().nullish(),
  x: z.string().nullish(),
  linkedin: z.string().nullish(),
  youtube: z.string().nullish(),
  tiktok: z.string().nullish(),
});

export const seoSettingsSchema = z.object({
  website_title: z.string().nullish(),
  meta_title: z.string().nullish(),
  meta_description: z.string().nullish(),
  meta_keywords: z.string().nullish(),
  indexable: z.boolean().nullish(),
});

export const analyticsSettingsSchema = z.object({
  google_analytics_id: z.string().nullish(),
  facebook_pixel_id: z.string().nullish(),
});

export const businessSettingsSchema = z.object({
  currency: z.string().nullish(),
  currency_symbol: z.string().nullish(),
  tax_rate: z.number().nullish(),
  vat_rate: z.number().nullish(),
  order_prefix: z.string().nullish(),
  invoice_prefix: z.string().nullish(),
});

export const featureSettingsSchema = z.object({
  wishlist_enabled: z.boolean().nullish(),
  reviews_enabled: z.boolean().nullish(),
  guest_checkout_enabled: z.boolean().nullish(),
});

/**
 * `.partial()` because a group is absent from the response entirely when it
 * holds no publicly-exposed settings, and `?group=` narrows to just one.
 */
export const publicSettingsSchema = z
  .object({
    general: generalSettingsSchema,
    branding: brandingSettingsSchema,
    theme: themeSettingsSchema,
    contact: contactSettingsSchema,
    social: socialSettingsSchema,
    seo: seoSettingsSchema,
    analytics: analyticsSettingsSchema,
    business: businessSettingsSchema,
    feature: featureSettingsSchema,
  })
  .partial();

export type GeneralSettings = z.infer<typeof generalSettingsSchema>;
export type BrandingSettings = z.infer<typeof brandingSettingsSchema>;
export type ThemeSettings = z.infer<typeof themeSettingsSchema>;
export type ContactSettings = z.infer<typeof contactSettingsSchema>;
export type SocialSettings = z.infer<typeof socialSettingsSchema>;
export type SeoSettings = z.infer<typeof seoSettingsSchema>;
export type AnalyticsSettings = z.infer<typeof analyticsSettingsSchema>;
export type BusinessSettings = z.infer<typeof businessSettingsSchema>;
export type FeatureSettings = z.infer<typeof featureSettingsSchema>;
export type PublicSettings = z.infer<typeof publicSettingsSchema>;

export type SettingsGroupName = keyof PublicSettings;
