import { apiClient } from '@/lib/api/client';
import { ApiError } from '@/lib/api/errors';
import { z } from 'zod';

/**
 * Health endpoint access.
 *
 * Used by the connection-status panel on the homepage, which exists to make
 * the frontend/backend link verifiable at a glance during this foundation
 * phase.
 */

export const livenessSchema = z.object({
  status: z.string(),
  timestamp: z.string(),
});

export const dependencyCheckSchema = z.object({
  status: z.enum(['ok', 'degraded', 'down']),
  critical: z.boolean(),
  latency_ms: z.number().nullable(),
  message: z.string().nullable(),
  details: z.record(z.unknown()).optional(),
});

export const readinessSchema = z.object({
  status: z.enum(['ok', 'degraded', 'down']),
  checks: z.record(dependencyCheckSchema),
});

export type Liveness = z.infer<typeof livenessSchema>;
export type DependencyCheck = z.infer<typeof dependencyCheckSchema>;
export type Readiness = z.infer<typeof readinessSchema>;

export interface ReadinessResult {
  readiness: Readiness;
  meta: Record<string, unknown>;
}

export async function fetchLiveness(): Promise<Liveness> {
  const result = await apiClient.get<unknown>('/health', {
    // Never cached: a cached health check reports the state of the past.
    cache: 'no-store',
    timeout: 5000,
  });

  return livenessSchema.parse(result.data);
}

export async function fetchReadiness(): Promise<ReadinessResult> {
  try {
    const result = await apiClient.get<unknown>('/health/ready', {
      cache: 'no-store',
      timeout: 8000,
    });

    return {
      readiness: readinessSchema.parse(result.data),
      meta: result.meta as Record<string, unknown>,
    };
  } catch (error) {
    // A 503 from this endpoint is a *report*, not a transport failure — the
    // API deliberately returns 503 so orchestrators drain the instance. The
    // client throws on non-2xx, so unwrap that case back into data rather
    // than losing the per-dependency detail the response carries.
    if (error instanceof ApiError && error.status === 503) {
      return {
        readiness: { status: 'down', checks: {} },
        meta: { unavailable: true, message: error.message },
      };
    }

    throw error;
  }
}
