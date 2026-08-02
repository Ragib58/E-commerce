import { apiClient } from '@/lib/api/client';
import { CACHE_TAGS, REVALIDATE_SECONDS } from '@/config/cache';
import { publicSettingsSchema, type PublicSettings, type SettingsGroupName } from '../types';

/**
 * Data access for admin-managed storefront configuration.
 *
 * This module is the reason no branding value is hardcoded anywhere else: the
 * company name, logo, favicon, colours, contact details, and social links all
 * originate here.
 */

/**
 * Last-resort defaults used only when the API is unreachable.
 *
 * These are NOT the site's configuration — they exist so a transient backend
 * outage renders a neutral, unbranded but functional page instead of a crash.
 * The real values always come from the admin panel. Deliberately generic:
 * showing a stale hardcoded company name would be worse than showing none.
 */
export const FALLBACK_SETTINGS: PublicSettings = {
  general: {
    company_name: 'Store',
    tagline: null,
    maintenance_mode: false,
  },
  branding: {
    logo: null,
    logo_light: null,
    logo_dark: null,
    favicon: null,
    og_image: null,
    brand_description: null,
  },
  theme: {
    primary_color: '#2563eb',
    secondary_color: '#64748b',
    accent_color: '#f59e0b',
    background_color: '#ffffff',
    foreground_color: '#0f172a',
    button_color: null,
    destructive_color: '#dc2626',
    radius: '0.5rem',
  },
  contact: {},
  social: {},
  seo: { indexable: false },
  // No tracking tags by default: injecting a measurement script the operator
  // did not configure would be a privacy problem, not a missing feature.
  analytics: { google_analytics_id: null, facebook_pixel_id: null },
  business: {
    currency: 'USD',
    currency_symbol: '$',
    tax_rate: 0,
    vat_rate: 0,
    order_prefix: 'ORD-',
    invoice_prefix: 'INV-',
  },
  feature: {},
};

export interface SettingsPayload {
  settings: PublicSettings;
  version: string;
  /** True when the API could not be reached and FALLBACK_SETTINGS were used. */
  isFallback: boolean;
}

/**
 * Fetch the public settings payload.
 *
 * Cached by tag rather than by time alone: when an administrator saves a
 * change, Laravel calls this app's /api/revalidate endpoint, which purges the
 * `settings` tag. The revalidate window is only a backstop for a missed
 * webhook.
 */
export async function fetchPublicSettings(group?: SettingsGroupName): Promise<SettingsPayload> {
  try {
    const result = await apiClient.get<unknown>('/settings/public', {
      params: group ? { group } : undefined,
      next: {
        revalidate: REVALIDATE_SECONDS.settings,
        tags: [CACHE_TAGS.settings],
      },
    });

    const parsed = publicSettingsSchema.safeParse(result.data);

    if (!parsed.success) {
      // A shape change in the API should be loud in logs but must not take
      // the storefront down.
      console.error('[settings] Response failed validation.', parsed.error.flatten());

      return { settings: FALLBACK_SETTINGS, version: '0', isFallback: true };
    }

    return {
      settings: parsed.data,
      version: typeof result.meta.version === 'string' ? result.meta.version : '1',
      isFallback: false,
    };
  } catch (error) {
    console.error('[settings] Failed to load public settings.', error);

    return { settings: FALLBACK_SETTINGS, version: '0', isFallback: true };
  }
}

/**
 * Merge a partial payload over the fallbacks so every consumer can rely on a
 * value being present without repeating `?? default` at each call site.
 */
export function withDefaults(settings: PublicSettings): PublicSettings {
  return {
    general: { ...FALLBACK_SETTINGS.general, ...settings.general },
    branding: { ...FALLBACK_SETTINGS.branding, ...settings.branding },
    theme: { ...FALLBACK_SETTINGS.theme, ...settings.theme },
    contact: { ...FALLBACK_SETTINGS.contact, ...settings.contact },
    social: { ...FALLBACK_SETTINGS.social, ...settings.social },
    seo: { ...FALLBACK_SETTINGS.seo, ...settings.seo },
    analytics: { ...FALLBACK_SETTINGS.analytics, ...settings.analytics },
    business: { ...FALLBACK_SETTINGS.business, ...settings.business },
    feature: { ...FALLBACK_SETTINGS.feature, ...settings.feature },
  };
}
