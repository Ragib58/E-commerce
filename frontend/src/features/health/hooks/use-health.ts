'use client';

import { useQuery } from '@tanstack/react-query';
import { queryKeys } from '@/config/query-keys';
import { QUERY_STALE_TIME } from '@/config/cache';
import { fetchReadiness, type ReadinessResult } from '../api';

/**
 * Live readiness of the backend and its dependencies.
 *
 * Polls on an interval because the value is a point-in-time observation, not
 * cacheable state — but only while the tab is visible, so a backgrounded tab
 * does not keep hitting the API.
 */
export function useReadiness(options?: { refetchInterval?: number }) {
  return useQuery<ReadinessResult>({
    queryKey: queryKeys.health.readiness(),
    queryFn: () => fetchReadiness(),
    staleTime: QUERY_STALE_TIME.health,
    refetchInterval: options?.refetchInterval ?? 30_000,
    refetchIntervalInBackground: false,
    // A failing health check is the answer, not an error to retry away.
    retry: 1,
  });
}
