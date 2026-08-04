/**
 * The guest cart credential.
 *
 * ## Why this is not a plain cookie sent by the API
 *
 * The Laravel API is stateless by design: Sanctum's cookie middleware is not
 * registered, so the API carries no ambient credential and therefore has no
 * CSRF surface. A cart cookie set by the API would be attached automatically to
 * every cross-site request, reintroducing exactly that surface — an attacker's
 * page could POST to `/cart/items` and the browser would helpfully include the
 * victim's cart identity.
 *
 * Instead the API returns the token in an `X-Cart-Token` response header, this
 * module stores it in a **first-party** cookie on the storefront's own origin,
 * and the client sends it back explicitly on each request. Nothing is attached
 * automatically, so a cross-site request carries no cart identity at all.
 *
 * ## Why a cookie rather than localStorage
 *
 * A cookie is readable by the Next.js server, which is what lets a server
 * component render the cart badge in the initial HTML instead of after a
 * client-side fetch. localStorage is invisible to the server, so the header
 * would flash an empty cart on every cold load.
 *
 * It is deliberately **not** httpOnly: this module has to read it to attach the
 * header. That is an accepted trade-off — the token identifies a basket, not an
 * account, and the cart API exposes no personal data. An account session is a
 * different matter and is held separately.
 */

const COOKIE_NAME = 'cart_token';

/** A year. A shopper who returns next month should find their basket. */
const MAX_AGE_SECONDS = 60 * 60 * 24 * 365;

/** Matches what the API mints and what its middleware will accept. */
const TOKEN_PATTERN = /^[a-f0-9]{64}$/;

/**
 * In-memory mirror.
 *
 * Reading `document.cookie` parses the entire cookie string, and the token is
 * read on every API call. This also means a token minted during the current
 * page load is available immediately, before the cookie round-trips.
 */
let cached: string | null = null;

export function getCartToken(): string | null {
  if (typeof document === 'undefined') return null;

  if (cached !== null) return cached;

  const match = document.cookie
    .split('; ')
    .find((entry) => entry.startsWith(`${COOKIE_NAME}=`));

  if (!match) return null;

  const value = decodeURIComponent(match.slice(COOKIE_NAME.length + 1));

  // Shape-checked before use. A malformed value would be sent as a header and
  // rejected by the API on every request; discarding it here lets a fresh token
  // be minted instead.
  cached = TOKEN_PATTERN.test(value) ? value : null;

  return cached;
}

/**
 * Persist a token minted by the API.
 *
 * `SameSite=Lax` rather than `Strict`: the cookie must survive a return from an
 * external payment redirect, which `Strict` would drop — the shopper would come
 * back to an empty basket. `Lax` still blocks it on cross-site POSTs, which is
 * the case that matters.
 */
export function setCartToken(token: string): void {
  if (typeof document === 'undefined' || !TOKEN_PATTERN.test(token)) return;

  if (cached === token) return;

  cached = token;

  const secure = window.location.protocol === 'https:' ? '; Secure' : '';

  document.cookie =
    `${COOKIE_NAME}=${encodeURIComponent(token)}` +
    `; Path=/; Max-Age=${MAX_AGE_SECONDS}; SameSite=Lax${secure}`;
}

/**
 * Forget the guest token.
 *
 * Called after signing in, once the guest cart has been merged into the
 * account's. Keeping it would leave a credential for a cart that no longer
 * exists, and every subsequent request would send a header the API ignores.
 */
export function clearCartToken(): void {
  if (typeof document === 'undefined') return;

  cached = null;

  document.cookie = `${COOKIE_NAME}=; Path=/; Max-Age=0; SameSite=Lax`;
}

/** The header name, shared with the API client and the Laravel middleware. */
export const CART_TOKEN_HEADER = 'X-Cart-Token';
