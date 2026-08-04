/**
 * Cache tags and revalidation windows.
 *
 * Tags are shared with the backend: Laravel's RevalidateFrontendCache job posts
 * these exact strings to /api/revalidate. A mismatch here silently breaks
 * admin-triggered invalidation, so both sides read from one list.
 */

export const CACHE_TAGS = {
  settings: 'settings',
  menus: 'menus',
  catalog: 'catalog',
  /**
   * The dynamic homepage, banners, and CMS pages.
   *
   * Separate from `catalog` because the two are invalidated by different
   * events: a price change must not discard the homepage layout, and a banner
   * swap must not discard every cached product page.
   */
  content: 'content',
} as const;

export type CacheTag = (typeof CACHE_TAGS)[keyof typeof CACHE_TAGS];

/**
 * ISR windows in seconds.
 *
 * These are backstops, not the primary invalidation mechanism — a settings
 * change purges its tag immediately. The window only bounds staleness if a
 * revalidation webhook is lost.
 */
export const REVALIDATE_SECONDS = {
  settings: 300,
  menus: 300,
  catalog: 60,
  /**
   * Short despite content changing rarely.
   *
   * Homepage sections carry scheduling windows, so a section can start or
   * expire with no admin action and therefore no revalidation webhook. This
   * window bounds how long a campaign can outlive its end date; the backend
   * additionally caps its own cache at the next scheduled transition.
   */
  content: 60,
} as const;

/** TanStack Query staleness windows, in milliseconds. */
export const QUERY_STALE_TIME = {
  settings: 5 * 60 * 1000,
  menus: 5 * 60 * 1000,
  catalog: 60 * 1000,
  content: 60 * 1000,
  health: 30 * 1000,
} as const;

export function isValidCacheTag(value: unknown): value is CacheTag {
  return typeof value === 'string' && Object.values(CACHE_TAGS).includes(value as CacheTag);
}
