import type { StoreConfig } from '@/features/settings/lib/store-config';

/**
 * Price formatting for catalog values.
 *
 * The API sends money as an integer count of minor units (cents), which is what
 * keeps arithmetic exact all the way from the database. Converting to a
 * displayable string is the last step, and it happens only here — a division by
 * 100 scattered across components is how a page ends up showing "19.989999".
 */

/**
 * Minor units in one major unit.
 *
 * Assumes a two-decimal currency, which covers every currency this store is
 * configured for. A zero-decimal currency (JPY) or three-decimal one (KWD)
 * would need this driven from the settings payload; until one is supported,
 * hardcoding it is honest about the assumption rather than hiding it behind a
 * lookup that is never exercised.
 */
const MINOR_UNITS_PER_MAJOR = 100;

/**
 * Format an integer minor-unit amount using the store's configured currency.
 */
export function formatMinorUnits(config: StoreConfig, minorUnits: number): string {
  const major = minorUnits / MINOR_UNITS_PER_MAJOR;

  try {
    return new Intl.NumberFormat(config.locale, {
      style: 'currency',
      currency: config.business.currency,
    }).format(major);
  } catch {
    // An invalid locale or currency code from settings must not crash a
    // product page; fall back to the configured symbol.
    return `${config.business.currencySymbol}${major.toFixed(2)}`;
  }
}

/**
 * The price a shopper pays, and the struck-through original when discounted.
 *
 * Returns the comparison price only when it is genuinely higher, so a product
 * whose discount equals its price does not render a strikethrough against an
 * identical number.
 */
export function resolveDisplayPrice(
  config: StoreConfig,
  pricing: { price: number; discount_price?: number | null; effective_price: number },
): { current: string; original: string | null; hasDiscount: boolean } {
  const hasDiscount =
    typeof pricing.discount_price === 'number' &&
    pricing.discount_price > 0 &&
    pricing.discount_price < pricing.price;

  return {
    current: formatMinorUnits(config, pricing.effective_price),
    original: hasDiscount ? formatMinorUnits(config, pricing.price) : null,
    hasDiscount,
  };
}

/**
 * Convert a major-unit figure typed into an admin form into minor units.
 *
 * Rounded rather than truncated: `parseFloat('19.99') * 100` is 1998.9999...
 * in IEEE-754, and truncating it would silently underprice the product by a
 * cent.
 */
export function toMinorUnits(major: number | string): number {
  const value = typeof major === 'string' ? Number.parseFloat(major) : major;

  return Number.isFinite(value) ? Math.round(value * MINOR_UNITS_PER_MAJOR) : 0;
}

/**
 * Convert stored minor units back into a major-unit number for a form field.
 */
export function toMajorUnits(minorUnits: number | null | undefined): number {
  return typeof minorUnits === 'number' ? minorUnits / MINOR_UNITS_PER_MAJOR : 0;
}
