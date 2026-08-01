import { z } from 'zod';

/**
 * Runtime environment validation.
 *
 * Parsing at module load means a missing or malformed variable fails the
 * process immediately with a readable message, rather than surfacing as
 * `fetch("undefined/settings/public")` on a customer's first request.
 *
 * Next.js inlines `process.env.NEXT_PUBLIC_*` at build time, so these must be
 * referenced as full literal property accesses — destructuring `process.env`
 * or indexing it dynamically breaks the substitution and yields undefined in
 * the browser bundle.
 */

const serverSchema = z.object({
  /**
   * Base URL the server (RSC, route handlers) uses to reach Laravel. Inside
   * Docker this is a service name that the browser cannot resolve, which is
   * exactly why it is separate from the public URL below.
   */
  INTERNAL_API_URL: z.string().url().optional(),

  /**
   * Shared secret Laravel presents when asking this app to drop cached
   * content. Without it the revalidation endpoint is unauthenticated and
   * anyone could force a cache stampede.
   */
  REVALIDATION_SECRET: z.string().min(16, 'REVALIDATION_SECRET must be at least 16 characters.').optional(),

  NODE_ENV: z.enum(['development', 'test', 'production']).default('development'),
});

const clientSchema = z.object({
  /** Base URL the browser uses to reach the API. Must be publicly resolvable. */
  NEXT_PUBLIC_API_URL: z.string().url('NEXT_PUBLIC_API_URL must be a valid absolute URL.'),

  NEXT_PUBLIC_API_VERSION: z.string().regex(/^v\d+$/, 'NEXT_PUBLIC_API_VERSION must look like "v1".').default('v1'),

  NEXT_PUBLIC_SITE_URL: z.string().url().optional(),
});

const parsedClient = clientSchema.safeParse({
  NEXT_PUBLIC_API_URL: process.env.NEXT_PUBLIC_API_URL,
  NEXT_PUBLIC_API_VERSION: process.env.NEXT_PUBLIC_API_VERSION,
  NEXT_PUBLIC_SITE_URL: process.env.NEXT_PUBLIC_SITE_URL,
});

if (!parsedClient.success) {
  throw new Error(
    `Invalid public environment variables:\n${parsedClient.error.issues
      .map((issue) => `  - ${issue.path.join('.')}: ${issue.message}`)
      .join('\n')}`,
  );
}

const parsedServer = serverSchema.safeParse({
  INTERNAL_API_URL: process.env.INTERNAL_API_URL,
  REVALIDATION_SECRET: process.env.REVALIDATION_SECRET,
  NODE_ENV: process.env.NODE_ENV,
});

if (!parsedServer.success) {
  throw new Error(
    `Invalid server environment variables:\n${parsedServer.error.issues
      .map((issue) => `  - ${issue.path.join('.')}: ${issue.message}`)
      .join('\n')}`,
  );
}

const client = parsedClient.data;
const server = parsedServer.data;

export const env = {
  ...client,
  ...server,
  isProduction: server.NODE_ENV === 'production',
  isDevelopment: server.NODE_ENV === 'development',
} as const;

/**
 * The correct API base URL for the current execution context.
 *
 * Server-side rendering prefers the internal Docker network address — it
 * avoids a round-trip out through the host and works even when the public
 * hostname is not resolvable from inside the container. The browser must
 * always use the public URL.
 */
export function resolveApiBaseUrl(): string {
  const isServer = typeof window === 'undefined';

  if (isServer && server.INTERNAL_API_URL) {
    return server.INTERNAL_API_URL.replace(/\/$/, '');
  }

  return `${client.NEXT_PUBLIC_API_URL.replace(/\/$/, '')}/${client.NEXT_PUBLIC_API_VERSION}`;
}
