# syntax=docker/dockerfile:1

###############################################################################
# Base
###############################################################################
FROM node:22-alpine AS base

# libc6-compat provides the glibc shims some native Node addons expect on
# Alpine's musl libc.
RUN apk add --no-cache libc6-compat

WORKDIR /app

###############################################################################
# Dependencies — cached independently of application source
###############################################################################
FROM base AS deps

COPY package.json package-lock.json* ./

# `npm ci` requires a lockfile and installs exactly what it pins. Fall back to
# `npm install` only on a first build before a lockfile is committed.
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi

###############################################################################
# Development — bind-mounted source with hot reload
###############################################################################
FROM base AS development

ENV NODE_ENV=development \
    NEXT_TELEMETRY_DISABLED=1

COPY --from=deps /app/node_modules ./node_modules
COPY . .

EXPOSE 3000

# --hostname 0.0.0.0 is required inside a container; Next.js binds to
# localhost by default, which is unreachable from outside the container.
CMD ["npm", "run", "dev", "--", "--hostname", "0.0.0.0", "--port", "3000"]

###############################################################################
# Builder — produces the standalone production bundle
###############################################################################
FROM base AS builder

ENV NEXT_TELEMETRY_DISABLED=1

COPY --from=deps /app/node_modules ./node_modules
COPY . .

# NEXT_PUBLIC_* values are inlined into the client bundle at build time, so
# they must be present here — not merely at runtime. Supplying them as build
# args means a different environment requires a rebuild, which is inherent to
# how Next.js compiles public env vars.
ARG NEXT_PUBLIC_API_URL
ARG NEXT_PUBLIC_API_VERSION=v1
ARG NEXT_PUBLIC_SITE_URL
ARG NEXT_PUBLIC_ASSET_HOSTNAME

ENV NEXT_PUBLIC_API_URL=$NEXT_PUBLIC_API_URL \
    NEXT_PUBLIC_API_VERSION=$NEXT_PUBLIC_API_VERSION \
    NEXT_PUBLIC_SITE_URL=$NEXT_PUBLIC_SITE_URL \
    NEXT_PUBLIC_ASSET_HOSTNAME=$NEXT_PUBLIC_ASSET_HOSTNAME

RUN npm run build

###############################################################################
# Production — minimal runtime image
###############################################################################
FROM base AS production

ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    PORT=3000 \
    HOSTNAME=0.0.0.0

RUN addgroup -g 1001 -S nodejs \
    && adduser -u 1001 -S nextjs -G nodejs

COPY --from=builder /app/public ./public

# `output: 'standalone'` emits a self-contained server with only the modules
# actually imported, so node_modules is not copied into the final image.
COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static ./.next/static

USER nextjs

EXPOSE 3000

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD node -e "fetch('http://127.0.0.1:3000/').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

CMD ["node", "server.js"]
