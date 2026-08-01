'use client';

import { QueryClientProvider } from '@tanstack/react-query';
import { ReactQueryDevtools } from '@tanstack/react-query-devtools';
import { useState, type ReactNode } from 'react';
import { getQueryClient } from '@/lib/query/client';

/**
 * Provides the TanStack Query client to the client component tree.
 *
 * The client is created inside useState rather than at module scope so that a
 * re-render never recreates it, while React Strict Mode's double-invocation in
 * development still yields a single instance.
 */
export function QueryProvider({ children }: { children: ReactNode }) {
  const [queryClient] = useState(() => getQueryClient());

  return (
    <QueryClientProvider client={queryClient}>
      {children}
      {process.env.NODE_ENV === 'development' ? (
        <ReactQueryDevtools initialIsOpen={false} buttonPosition="bottom-right" />
      ) : null}
    </QueryClientProvider>
  );
}
