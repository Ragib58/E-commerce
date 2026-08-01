/**
 * Bearer token resolution for the API client.
 *
 * The client is shared by both realms, so it cannot know whether a given call
 * belongs to a customer or a staff session. Instead each realm registers a
 * resolver, and the client asks for the token matching the path it is about
 * to request.
 *
 * Tokens are held in memory here and mirrored into sessionStorage by the auth
 * stores. Deliberately NOT localStorage: a token in localStorage persists
 * across browser restarts and is readable by any script on the origin, so an
 * XSS bug becomes a permanent account compromise rather than a session-long
 * one. sessionStorage at least dies with the tab.
 *
 * A production hardening beyond this phase would move tokens to httpOnly
 * cookies with CSRF protection, which JavaScript cannot read at all.
 */

export type AuthRealm = 'customer' | 'admin';

type TokenResolver = () => string | null;

const resolvers: Record<AuthRealm, TokenResolver | null> = {
  customer: null,
  admin: null,
};

/** Called by each auth store once, at module init. */
export function registerTokenResolver(realm: AuthRealm, resolver: TokenResolver): void {
  resolvers[realm] = resolver;
}

export function getToken(realm: AuthRealm): string | null {
  return resolvers[realm]?.() ?? null;
}

/**
 * Infer the realm from the request path.
 *
 * `/admin/*` is staff; everything else is customer. Keeping this as one
 * function means a new admin endpoint is covered automatically rather than
 * requiring every call site to pass a realm.
 */
export function realmForPath(path: string): AuthRealm {
  const normalised = path.startsWith('/') ? path : `/${path}`;

  return normalised.startsWith('/admin') ? 'admin' : 'customer';
}
