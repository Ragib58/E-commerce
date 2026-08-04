/**
 * Typed readers for a section's loosely-typed `settings` record.
 *
 * Settings arrive as `Record<string, unknown>` on purpose — see the note on
 * `sectionSettingsSchema` — so every read needs a type check and a fallback.
 * Doing that inline in each renderer would put a dozen `typeof x === 'number'`
 * guards through the components; these helpers keep the renderers declarative
 * and make the fallback for each key visible in one place.
 *
 * The backend already merges each type's defaults in before responding, so the
 * fallbacks here are a second line of defence rather than the primary one.
 */

export type SectionSettings = Record<string, unknown>;

export function settingString(
  settings: SectionSettings,
  key: string,
  fallback = '',
): string {
  const value = settings[key];

  return typeof value === 'string' ? value : fallback;
}

export function settingNullableString(
  settings: SectionSettings,
  key: string,
): string | null {
  const value = settings[key];

  return typeof value === 'string' && value.trim() !== '' ? value : null;
}

export function settingBoolean(
  settings: SectionSettings,
  key: string,
  fallback: boolean,
): boolean {
  const value = settings[key];

  return typeof value === 'boolean' ? value : fallback;
}

/**
 * A numeric setting, clamped.
 *
 * Clamping matters because these values drive grid column counts and carousel
 * timings: a `columns` of 40 arriving from a bad save would produce an
 * unreadable page rather than a validation error the visitor can act on.
 */
export function settingNumber(
  settings: SectionSettings,
  key: string,
  fallback: number,
  min = Number.NEGATIVE_INFINITY,
  max = Number.POSITIVE_INFINITY,
): number {
  const value = settings[key];
  const parsed = typeof value === 'number' ? value : Number(value);

  if (!Number.isFinite(parsed)) return fallback;

  return Math.min(max, Math.max(min, parsed));
}

/**
 * Tailwind classes for a responsive product/category grid.
 *
 * Written as a lookup rather than an interpolated `md:grid-cols-${n}`: Tailwind
 * scans source files statically, so a class name assembled at runtime is never
 * emitted into the stylesheet and the grid silently collapses to one column.
 *
 * Every layout starts at one or two columns on the narrowest screens and steps
 * up — the admin's `columns` value is the ceiling on a large viewport, not a
 * fixed count, which is what keeps a 4-up rail readable on a phone.
 */
const DEFAULT_GRID = 'grid-cols-2 md:grid-cols-3 lg:grid-cols-4';

const GRID_COLUMNS: Record<number, string> = {
  1: 'grid-cols-1',
  2: 'grid-cols-2',
  3: 'grid-cols-2 md:grid-cols-3',
  4: DEFAULT_GRID,
  5: 'grid-cols-2 md:grid-cols-3 lg:grid-cols-5',
  6: 'grid-cols-2 md:grid-cols-4 lg:grid-cols-6',
};

export function gridColumnsClass(columns: number): string {
  return GRID_COLUMNS[Math.min(6, Math.max(1, Math.round(columns)))] ?? DEFAULT_GRID;
}

/** Column classes for text-based cards, which need more width than a product tile. */
const DEFAULT_TEXT_GRID = 'grid-cols-1 md:grid-cols-2 lg:grid-cols-3';

const TEXT_GRID_COLUMNS: Record<number, string> = {
  1: 'grid-cols-1',
  2: 'grid-cols-1 md:grid-cols-2',
  3: DEFAULT_TEXT_GRID,
  4: 'grid-cols-1 md:grid-cols-2 lg:grid-cols-4',
};

export function textGridColumnsClass(columns: number): string {
  return TEXT_GRID_COLUMNS[Math.min(4, Math.max(1, Math.round(columns)))] ?? DEFAULT_TEXT_GRID;
}

/**
 * Container width, from the section's style block.
 *
 * `full` deliberately keeps horizontal padding at zero so a hero can bleed to
 * the viewport edges — the one case where a section is meant to escape the
 * page's reading measure.
 */
export function containerClass(width: string | undefined): string {
  switch (width) {
    case 'full':
      return 'w-full';
    case 'wide':
      return 'mx-auto w-full max-w-[1600px] px-4 sm:px-6';
    case 'narrow':
      return 'mx-auto w-full max-w-3xl px-4 sm:px-6';
    default:
      return 'mx-auto w-full max-w-7xl px-4 sm:px-6';
  }
}

/** Hero heights, as a lookup for the same reason as the grid columns. */
const DEFAULT_HERO_HEIGHT = 'aspect-[4/5] sm:aspect-[16/9] lg:aspect-[21/9] max-h-[620px]';

const HERO_HEIGHTS: Record<string, string> = {
  small: 'aspect-[16/7] sm:aspect-[21/7] max-h-[320px]',
  medium: 'aspect-[16/9] sm:aspect-[21/8] max-h-[460px]',
  large: DEFAULT_HERO_HEIGHT,
  full: 'min-h-[70svh]',
};

export function heroHeightClass(height: string): string {
  return HERO_HEIGHTS[height] ?? DEFAULT_HERO_HEIGHT;
}

/** Aspect ratios for promotional banners. */
const DEFAULT_ASPECT_RATIO = 'aspect-[21/9]';

const ASPECT_RATIOS: Record<string, string> = {
  '21:9': DEFAULT_ASPECT_RATIO,
  '16:9': 'aspect-[16/9]',
  '4:3': 'aspect-[4/3]',
  '3:1': 'aspect-[3/1]',
  '1:1': 'aspect-square',
};

export function aspectRatioClass(ratio: string): string {
  return ASPECT_RATIOS[ratio] ?? DEFAULT_ASPECT_RATIO;
}

/**
 * A safe background colour for a section.
 *
 * Re-validated here even though the API validates on write: this value reaches
 * an inline style attribute, and the cost of the check is one regex against the
 * cost of trusting a payload from another deployable.
 */
export function safeBackgroundColor(value: string | null | undefined): string | undefined {
  if (!value) return undefined;

  return /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(value) ? value : undefined;
}

/**
 * Whether a URL is safe to place in an href.
 *
 * Mirrors the backend's link validation. Both ends check because either alone
 * is a single point of failure for a stored-XSS vector, and this one also
 * covers content written before the backend rule existed.
 */
export function safeUrl(value: string | null | undefined): string | null {
  if (!value) return null;

  const trimmed = value.trim();

  // Strip control and whitespace characters before reading the scheme.
  // `java\tscript:` is treated as a scheme by browsers but would slip past a
  // naive check against the raw string.
  const normalised = trimmed.replace(/[\u0000-\u0020]+/g, '').toLowerCase();

  if (normalised.startsWith('//')) return null;
  if (trimmed.startsWith('/') || trimmed.startsWith('#')) return trimmed;

  return /^https?:\/\//.test(normalised) ? trimmed : null;
}
