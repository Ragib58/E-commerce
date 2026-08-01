# Setup

## Prerequisites

- Docker Engine 20.10+ and Docker Compose v2
- Node.js 20.9+ (only if running the frontend outside Docker)
- Ports free: 8080, 3000, 3307, 6380, 9002, 9003, 8025

The host does **not** need PHP or Composer — everything PHP runs inside the
`php:8.3-fpm-alpine` image.

## Quick start

```bash
# 1. Environment files
cp .env.example .env
cp backend/.env.example backend/.env
cp frontend/.env.example frontend/.env.local

# 2. Set the revalidation secret — it MUST match in both files
#    backend/.env  -> FRONTEND_REVALIDATION_SECRET=<value>
#    frontend/.env.local -> REVALIDATION_SECRET=<value>
#    Minimum 16 characters, or the frontend refuses to start.

# 3. Build and start
docker compose up -d --build

# 4. Install PHP dependencies and generate the app key
docker compose exec php composer install
docker compose exec php php artisan key:generate

# 5. Create the schema and seed the default (editable) storefront config
docker compose exec php php artisan migrate --seed

# 6. Link the public storage disk so uploaded assets resolve
docker compose exec php php artisan storage:link
```

### The bootstrap Super Admin

Step 5 also seeds roles, permissions, and one Super Admin. If
`SUPER_ADMIN_PASSWORD` is unset in `backend/.env`, a strong password is
generated and **printed once** — copy it immediately. Nothing stores the
plaintext, so a lost password can only be recovered through the reset flow.

There is deliberately no hardcoded default such as `admin123`: a well-known
default administrator password is among the most reliably exploited weaknesses
in self-hosted software, so an installation cannot ship with known credentials.

Sign in at http://localhost:3000/admin/login.

On Linux/macOS, set `UID`/`GID` in the root `.env` to your own
(`id -u` / `id -g`) before building, so container-written files stay editable.

## URLs

| Service | URL | Notes |
|---|---|---|
| Storefront | http://localhost:8080 | Through Nginx — mirrors production routing |
| Storefront (direct) | http://localhost:3000 | Bypasses Nginx; useful for debugging |
| Admin panel | http://localhost:8080/admin | Unguarded in this phase |
| API health | http://localhost:8080/api/v1/health | |
| API readiness | http://localhost:8080/api/v1/health/ready | |
| Public settings | http://localhost:8080/api/v1/settings/public | |
| MinIO console | http://localhost:9003 | `minioadmin` / `minioadmin` |
| Mailpit | http://localhost:8025 | Captures all outbound mail |

Prefer `:8080` over `:3000`. It is the only origin that mirrors production
routing, so CORS behaves there exactly as it will in production.

## Running the frontend outside Docker

Useful for faster hot reload:

```bash
docker compose up -d nginx php mysql redis minio    # backend only
cd frontend
npm install
npm run dev
```

Set `INTERNAL_API_URL=http://localhost:8080/api/v1` in `frontend/.env.local` —
the Docker service name `nginx` is not resolvable from the host.

## Switching to PostgreSQL

The schema uses no engine-specific types. In `backend/.env`:

```env
DB_CONNECTION=pgsql
DB_PORT=5432
```

Replace the `mysql` service in `docker-compose.yml` with `postgres:16-alpine`
and re-run migrations. No migration or model changes are required.

## Common issues

**`SQLSTATE[HY000] [2002] Connection refused`**
MySQL is still initialising. The compose healthcheck gates the PHP containers
on it, but a manual `artisan` call can still race a cold start. Wait for
`docker compose ps` to show `mysql` as healthy.

**Settings changes do not appear on the storefront**
Check, in order:
1. `CACHE_STORE=redis` — tags no-op on the `file` driver, so nothing is
   invalidated.
2. The queue worker is running: `docker compose ps queue`.
3. The two secrets match: `FRONTEND_REVALIDATION_SECRET` in `backend/.env` and
   `REVALIDATION_SECRET` in `frontend/.env.local`.
4. `docker compose logs queue | grep -i revalidat`

**Frontend exits immediately on start**
Environment validation failed. The error names the offending variable —
usually `NEXT_PUBLIC_API_URL` missing or `REVALIDATION_SECRET` under 16
characters. This is intentional: failing at boot beats failing on a customer's
first request.

**CORS errors in the browser**
`CORS_ALLOWED_ORIGINS` must list the exact origin including the port, and a
wildcard is invalid because `CORS_SUPPORTS_CREDENTIALS=true`. Using
`http://localhost:8080` for both tiers avoids CORS entirely.

**Permission denied writing to `storage/`**
`UID`/`GID` in the root `.env` do not match your host user. Set them and
rebuild: `docker compose build php && docker compose up -d`.

**Admin login rejected with "This account has no assigned role"**
The account exists but holds no role, so it would authenticate into an empty
panel. Assign one, or re-run
`php artisan db:seed --class=RolesAndPermissionsSeeder` if the roles table is
empty.

**A permission change does not take effect**
Resolved permission sets are cached per admin. They are flushed automatically
on every role or permission change, so this should not happen — if it does,
`php artisan cache:clear` confirms whether the cache is the cause, and a
missing `flushPermissionCache()` call is the likely bug.

**429 during testing**
Credential endpoints are rate limited to 5 attempts per minute per email+IP.
Clear it with `php artisan cache:clear`, or raise
`API_RATE_LIMIT_AUTH_ATTEMPTS` in `backend/.env` for local work.

**"You cannot assign a role that ranks at or above your own"**
Working as designed. Nobody may assign a role at or above their own level —
without that rule, any account holding `manage_roles` could create a Super
Admin and take over the installation. Use a Super Admin for the assignment.

**Password reset link 404s or shows "incomplete"**
`FRONTEND_URL` in `backend/.env` must point at the storefront, since the emailed
link targets a Next.js page. Check the message in Mailpit
(http://localhost:8025) to see the URL that was actually generated.
