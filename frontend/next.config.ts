import type { NextConfig } from 'next';

/**
 * Next.js configuration.
 *
 * Note what is deliberately absent: no company name, no logo path, no brand
 * colours. Everything visual is fetched from the Laravel settings API at
 * request time, so none of it can be baked into a build.
 */

const apiUrl = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8080/api';
const apiOrigin = (() => {
  try {
    return new URL(apiUrl).origin;
  } catch {
    return 'http://localhost:8080';
  }
})();

const nextConfig: NextConfig = {
  reactStrictMode: true,

  // Emits a minimal self-contained server bundle for the production Docker
  // stage, avoiding shipping the full node_modules tree in the image.
  output: 'standalone',

  poweredByHeader: false,

  images: {
    /**
     * Admin-uploaded assets are served from the Laravel public disk or an
     * S3-compatible bucket. Both origins must be allowed here or next/image
     * refuses to optimise them.
     *
     * Configured from the environment rather than hardcoded so a production
     * CDN hostname does not require a code change.
     */
    remotePatterns: [
      {
        protocol: 'http',
        hostname: 'localhost',
        port: '8080',
        pathname: '/storage/**',
      },
      {
        protocol: 'http',
        hostname: 'localhost',
        port: '9002',
        pathname: '/**',
      },
      {
        protocol: 'http',
        hostname: 'nginx',
        pathname: '/storage/**',
      },
      {
        protocol: 'http',
        hostname: 'minio',
        port: '9000',
        pathname: '/**',
      },
      ...(process.env.NEXT_PUBLIC_ASSET_HOSTNAME
        ? [
            {
              protocol: 'https' as const,
              hostname: process.env.NEXT_PUBLIC_ASSET_HOSTNAME,
              pathname: '/**',
            },
          ]
        : []),
    ],
    formats: ['image/avif', 'image/webp'],
  },

  async headers() {
    /**
     * Content Security Policy.
     *
     * The nginx config defers CSP to this layer, on the reasoning that only
     * the app knows its own script and style origins — so it has to actually
     * be defined here, or the deferral leaves the site with none.
     *
     * `'unsafe-inline'` on styles is required by Next.js, which injects
     * critical CSS inline. Scripts additionally need `'unsafe-eval'` in
     * development for React Refresh; production drops it, so the weaker
     * policy never ships.
     *
     * `connect-src` includes the API origin because the browser calls Laravel
     * cross-origin. `frame-ancestors 'none'` is the modern replacement for
     * X-Frame-Options, which is kept alongside it for older browsers.
     */
    const isDev = process.env.NODE_ENV !== 'production';

    const csp = [
      "default-src 'self'",
      `script-src 'self' 'unsafe-inline'${isDev ? " 'unsafe-eval'" : ''}`,
      "style-src 'self' 'unsafe-inline'",
      // data: and blob: cover inlined placeholders and next/image output.
      `img-src 'self' data: blob: ${apiOrigin}`,
      "font-src 'self' data:",
      `connect-src 'self' ${apiOrigin}${isDev ? ' ws: wss:' : ''}`,
      "object-src 'none'",
      "base-uri 'self'",
      "form-action 'self'",
      "frame-ancestors 'none'",
      ...(isDev ? [] : ['upgrade-insecure-requests']),
    ].join('; ');

    return [
      {
        source: '/:path*',
        headers: [
          { key: 'Content-Security-Policy', value: csp },
          { key: 'X-Content-Type-Options', value: 'nosniff' },
          { key: 'X-Frame-Options', value: 'SAMEORIGIN' },
          { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
          {
            key: 'Permissions-Policy',
            value: 'camera=(), microphone=(), geolocation=()',
          },
        ],
      },
    ];
  },

  env: {
    NEXT_PUBLIC_API_ORIGIN: apiOrigin,
  },

  // Next.js 16 removed the `eslint` config key; linting is a separate step
  // (`npm run lint`) and is enforced in CI rather than during the build.
  typescript: {
    ignoreBuildErrors: false,
  },
};

export default nextConfig;
