import type { PublicSettings } from '../types';

/**
 * The storefront's single view of admin-managed configuration.
 *
 * Components read branding through this rather than reaching into the raw
 * settings payload, for two reasons:
 *
 *   1. It resolves the derived rules in one place — which logo applies on a
 *      dark background, that the button colour falls back to the primary
 *      colour, how a price is formatted for the configured currency. Spread
 *      across components, those rules drift.
 *   2. It is a stable surface. The API's group/key shape can change without
 *      touching every consumer.
 *
 * Nothing here contains a brand value. Every field originates in the Laravel
 * settings API; the only literals are structural neutrals used when the API is
 * unreachable, and they are deliberately generic.
 */

export interface StoreConfig {
  readonly companyName: string;
  readonly tagline: string | null;
  readonly brandDescription: string | null;

  readonly logo: string | null;
  readonly logoLight: string | null;
  readonly logoDark: string | null;
  readonly favicon: string | null;
  readonly ogImage: string | null;

  readonly websiteTitle: string;
  readonly metaTitle: string;
  readonly metaDescription: string;
  readonly metaKeywords: string[];
  readonly indexable: boolean;

  readonly colors: {
    readonly primary: string | null;
    readonly secondary: string | null;
    readonly accent: string | null;
    readonly background: string | null;
    readonly text: string | null;
    readonly button: string | null;
    readonly destructive: string | null;
  };

  readonly radius: string | null;
  readonly fontFamily: string | null;

  readonly contact: {
    readonly email: string | null;
    readonly phone: string | null;
    readonly address: string | null;
    readonly googleMapsUrl: string | null;
    readonly supportHours: string | null;
  };

  /** Configured social profiles only — empty entries are dropped. */
  readonly social: ReadonlyArray<{ platform: SocialPlatform; url: string }>;

  readonly business: {
    readonly currency: string;
    readonly currencySymbol: string;
    readonly taxRate: number;
    readonly vatRate: number;
    readonly orderPrefix: string;
    readonly invoicePrefix: string;
  };

  readonly analytics: {
    readonly googleAnalyticsId: string | null;
    readonly facebookPixelId: string | null;
  };

  readonly features: {
    readonly wishlist: boolean;
    readonly reviews: boolean;
    readonly guestCheckout: boolean;
  };

  readonly locale: string;
  readonly maintenanceMode: boolean;
}

export type SocialPlatform = 'facebook' | 'instagram' | 'x' | 'linkedin' | 'youtube' | 'tiktok';

/** Render order for social links in the footer. */
const SOCIAL_PLATFORMS: readonly SocialPlatform[] = [
  'facebook',
  'instagram',
  'x',
  'youtube',
  'linkedin',
  'tiktok',
];

/** Treat whitespace-only admin input as unset. */
function clean(value: string | null | undefined): string | null {
  if (typeof value !== 'string') return null;

  const trimmed = value.trim();

  return trimmed === '' ? null : trimmed;
}

function cleanNumber(value: number | null | undefined, fallback: number): number {
  return typeof value === 'number' && Number.isFinite(value) ? value : fallback;
}

/**
 * Build the config from a settings payload that has already been merged with
 * defaults by `withDefaults`.
 */
export function buildStoreConfig(settings: PublicSettings): StoreConfig {
  const { general, branding, theme, contact, social, seo, analytics, business, feature } = settings;

  const companyName = clean(general?.company_name) ?? 'Store';
  const tagline = clean(general?.tagline);

  const logo = clean(branding?.logo);

  // The website title is the operator's explicit choice; the company name is
  // the sensible stand-in when they have not set one.
  const websiteTitle = clean(seo?.website_title) ?? companyName;

  const metaTitle =
    clean(seo?.meta_title) ?? (tagline ? `${websiteTitle} — ${tagline}` : websiteTitle);

  const metaDescription =
    clean(seo?.meta_description) ?? clean(general?.description) ?? clean(branding?.brand_description) ?? '';

  return {
    companyName,
    tagline,
    brandDescription: clean(branding?.brand_description),

    logo,
    // Both variants fall back to the primary logo, so an operator who uploads
    // only one asset still gets a logo everywhere.
    logoLight: clean(branding?.logo_light) ?? logo,
    logoDark: clean(branding?.logo_dark) ?? logo,
    favicon: clean(branding?.favicon),
    ogImage: clean(branding?.og_image),

    websiteTitle,
    metaTitle,
    metaDescription,
    metaKeywords:
      clean(seo?.meta_keywords)
        ?.split(',')
        .map((keyword) => keyword.trim())
        .filter(Boolean) ?? [],
    indexable: seo?.indexable === true,

    colors: {
      primary: clean(theme?.primary_color),
      secondary: clean(theme?.secondary_color),
      accent: clean(theme?.accent_color),
      background: clean(theme?.background_color),
      text: clean(theme?.foreground_color),
      // An unset button colour means "use the primary colour" rather than
      // "unstyled" — otherwise buttons would lose their fill the moment an
      // operator cleared the field.
      button: clean(theme?.button_color) ?? clean(theme?.primary_color),
      destructive: clean(theme?.destructive_color),
    },

    radius: clean(theme?.radius),
    fontFamily: clean(theme?.font_family),

    contact: {
      email: clean(contact?.email),
      phone: clean(contact?.phone),
      address: clean(contact?.address),
      googleMapsUrl: clean(contact?.google_maps_url),
      supportHours: clean(contact?.support_hours),
    },

    social: SOCIAL_PLATFORMS.flatMap((platform) => {
      const url = clean(social?.[platform]);

      return url ? [{ platform, url }] : [];
    }),

    business: {
      currency: clean(business?.currency) ?? 'USD',
      currencySymbol: clean(business?.currency_symbol) ?? '$',
      taxRate: cleanNumber(business?.tax_rate, 0),
      vatRate: cleanNumber(business?.vat_rate, 0),
      orderPrefix: clean(business?.order_prefix) ?? '',
      invoicePrefix: clean(business?.invoice_prefix) ?? '',
    },

    analytics: {
      googleAnalyticsId: clean(analytics?.google_analytics_id),
      facebookPixelId: clean(analytics?.facebook_pixel_id),
    },

    features: {
      wishlist: feature?.wishlist_enabled === true,
      reviews: feature?.reviews_enabled === true,
      guestCheckout: feature?.guest_checkout_enabled === true,
    },

    locale: clean(general?.locale) ?? 'en',
    maintenanceMode: general?.maintenance_mode === true,
  };
}

/**
 * Format an amount in the configured currency.
 *
 * Uses Intl with the admin's currency and locale, falling back to the
 * configured symbol when the code is not one Intl recognises — an operator can
 * type anything into that field, and a thrown RangeError must not take down a
 * product listing.
 */
export function formatPrice(config: StoreConfig, amount: number): string {
  try {
    return new Intl.NumberFormat(config.locale, {
      style: 'currency',
      currency: config.business.currency,
    }).format(amount);
  } catch {
    return `${config.business.currencySymbol}${amount.toFixed(2)}`;
  }
}

/**
 * Total tax rate as a fraction, e.g. 8.25% + 2% VAT -> 0.1025.
 *
 * Both rates are stored as percentages because that is how an operator thinks
 * about them; every consumer wants a multiplier.
 */
export function taxMultiplier(config: StoreConfig): number {
  return (config.business.taxRate + config.business.vatRate) / 100;
}
