import { resolveApiBaseUrl } from '@/lib/env';
import { getToken, realmForPath } from './auth-token';
import { CART_TOKEN_HEADER, getCartToken, setCartToken } from './cart-token';
import { ApiError, NetworkError } from './errors';
import type { ApiResponse, ApiResult, RequestOptions } from './types';

/**
 * The single HTTP client for the Laravel API.
 *
 * Every network call in the application goes through here — no component or
 * hook calls `fetch` directly. That concentration is what makes the envelope
 * unwrapping, timeout handling, and error normalisation apply uniformly, and
 * it means adding auth headers later is a one-file change.
 *
 * Works unmodified in both React Server Components and the browser; only the
 * resolved base URL and the caching options differ.
 */

const DEFAULT_TIMEOUT_MS = 10_000;

function buildUrl(path: string, params?: RequestOptions['params']): string {
  const base = resolveApiBaseUrl();
  const normalisedPath = path.startsWith('/') ? path : `/${path}`;
  const url = new URL(`${base}${normalisedPath}`);

  if (params) {
    for (const [key, value] of Object.entries(params)) {
      // Undefined and null are omitted rather than serialised as the strings
      // "undefined"/"null", which the API would reject as invalid input.
      if (value !== undefined && value !== null) {
        url.searchParams.set(key, String(value));
      }
    }
  }

  return url.toString();
}

async function request<TData>(
  method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE',
  path: string,
  options: RequestOptions = {},
): Promise<ApiResult<TData>> {
  const { params, body, timeout = DEFAULT_TIMEOUT_MS, headers, next, ...rest } = options;

  const url = buildUrl(path, params);

  // AbortSignal.timeout is the modern equivalent of a manual controller +
  // setTimeout, and cannot leak the timer if the request settles first.
  const signal = rest.signal ?? AbortSignal.timeout(timeout);

  const requestHeaders = new Headers({
    Accept: 'application/json',
    ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
    ...(headers as Record<string, string> | undefined),
  });

  // Attach the bearer token for the realm this path belongs to, unless the
  // caller supplied an Authorization header explicitly (the reset flows pass
  // a one-off token). Resolvers only exist in the browser, so server-side
  // rendering of public pages is unaffected.
  if (!requestHeaders.has('Authorization')) {
    const token = getToken(realmForPath(path));

    if (token !== null) {
      requestHeaders.set('Authorization', `Bearer ${token}`);
    }
  }

  /*
   * Attach the guest cart credential.
   *
   * Sent explicitly rather than carried in a cookie the browser attaches
   * automatically — see cart-token.ts. Only when the caller has not set it
   * itself, so a server component passing a token read from the request's
   * cookies is not overwritten by the browser-only resolver (which returns
   * null on the server anyway).
   */
  if (!requestHeaders.has(CART_TOKEN_HEADER)) {
    const cartToken = getCartToken();

    if (cartToken !== null) {
      requestHeaders.set(CART_TOKEN_HEADER, cartToken);
    }
  }

  let response: Response;

  try {
    response = await fetch(url, {
      ...rest,
      method,
      headers: requestHeaders,
      body: body !== undefined ? JSON.stringify(body) : undefined,
      signal,
      ...(next ? { next } : {}),
    });
  } catch (cause) {
    const isTimeout = cause instanceof DOMException && cause.name === 'TimeoutError';

    throw new NetworkError(
      isTimeout
        ? `Request to ${path} timed out after ${timeout}ms.`
        : `Unable to reach the API at ${path}.`,
      cause,
    );
  }

  const requestId = response.headers.get('X-Request-Id') ?? undefined;

  /*
   * Capture a newly minted guest cart token.
   *
   * The API returns one the first time an anonymous visitor touches the cart.
   * Persisting it here — rather than in each cart hook — means every path that
   * can create a cart stores its credential, so a shopper never ends up with a
   * server-side cart they cannot reach again.
   *
   * Requires `X-Cart-Token` in the API's CORS `exposed_headers`; without that
   * the browser hides it and every request would mint a fresh empty cart.
   */
  const cartToken = response.headers.get(CART_TOKEN_HEADER);

  if (cartToken !== null) {
    setCartToken(cartToken);
  }

  // 204 carries no body; parsing it would throw on the empty string.
  if (response.status === 204) {
    return { data: null as TData, meta: {}, message: '' };
  }

  let payload: ApiResponse<TData> | null = null;

  try {
    payload = (await response.json()) as ApiResponse<TData>;
  } catch {
    // A non-JSON body means something upstream (a proxy, a crashed worker)
    // answered instead of the API — surface it as an error, not a parse crash.
    throw new ApiError({
      message: `The API returned a malformed response (HTTP ${response.status}).`,
      status: response.status,
      code: 'MALFORMED_RESPONSE',
      requestId,
    });
  }

  if (!response.ok || payload?.success === false) {
    throw ApiError.fromResponse(
      payload && payload.success === false ? payload : null,
      response.status,
      requestId,
    );
  }

  const success = payload as Extract<ApiResponse<TData>, { success: true }>;

  return {
    data: success.data,
    meta: success.meta ?? {},
    message: success.message,
  };
}

export const apiClient = {
  get: <TData>(path: string, options?: RequestOptions) => request<TData>('GET', path, options),
  post: <TData>(path: string, options?: RequestOptions) => request<TData>('POST', path, options),
  put: <TData>(path: string, options?: RequestOptions) => request<TData>('PUT', path, options),
  patch: <TData>(path: string, options?: RequestOptions) => request<TData>('PATCH', path, options),
  delete: <TData>(path: string, options?: RequestOptions) => request<TData>('DELETE', path, options),
} as const;

export { ApiError, NetworkError };
export type { ApiResult, RequestOptions };
