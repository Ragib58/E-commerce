import { QueryClient, isServer } from '@tanstack/react-query';
import { ApiError } from '@/lib/api/errors';

/**
 * TanStack Query client factory and per-context accessor.
 */
function makeQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        // Above zero so a server-prefetched query is not immediately refetched
        // on the client the moment it hydrates.
        staleTime: 60 * 1000,
        gcTime: 5 * 60 * 1000,

        // Retrying a 404 or a 422 cannot succeed and only delays the error
        // reaching the user.
        retry: (failureCount, error) => {
          if (error instanceof ApiError && !error.isRetryable) {
            return false;
          }

          return failureCount < 2;
        },

        retryDelay: (attemptIndex) => Math.min(1000 * 2 ** attemptIndex, 30_000),

        refetchOnWindowFocus: false,
        refetchOnReconnect: true,
      },
      mutations: {
        retry: false,
      },
    },
  });
}

let browserQueryClient: QueryClient | undefined;

/**
 * Return the correct client for the current execution context.
 *
 * The server must get a fresh client per request — a shared one would leak one
 * user's cached data into another user's response. The browser must reuse a
 * single client, or every render would discard the cache.
 */
export function getQueryClient(): QueryClient {
  if (isServer) {
    return makeQueryClient();
  }

  browserQueryClient ??= makeQueryClient();

  return browserQueryClient;
}
