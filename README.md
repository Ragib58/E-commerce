# E-commerce Platform

A production-ready, fully dynamic e-commerce platform. Laravel 12 API + Blade
admin panel, Next.js 16 storefront, MySQL, Redis, and S3-compatible storage —
all containerised.

**Phases 1–4 complete:** foundation; authentication and role-based access
control; dynamic store settings, branding, and theme management; product
catalog and inventory. Orders and payments are later phases.

## Catalog and inventory

Categories nest to unlimited depth, brands are flat, and products come in four
types — simple, variable, digital, and customizable — with the type deciding
what else about a product is meaningful (whether variants are required, whether
stock is tracked, whether shipping fields apply).

Variants are built from **dynamic attributes**: "Size" and "Colour" are rows in
the database, not columns and not an enum, so adding "Material: cotton | linen"
from the admin panel is an `INSERT` rather than a migration. Each attribute
carries its own storefront control (swatch, button, dropdown), which is what
lets a new attribute render correctly with no frontend change.

Two guarantees hold across the inventory layer:

- **Every stock change is journalled.** `InventoryService` is the only code
  permitted to write a stock level, and it writes the level and its ledger row
  in one transaction. The ledger is append-only — enforced by the model, not
  merely documented — so a correction is a new opposing movement, never an edit.
- **Concurrent sales cannot oversell.** Each mutation re-reads its row under
  `lockForUpdate()` inside the transaction, so the textbook lost-update race
  (two requests both read 1, both write 0, two customers promised the last unit)
  cannot occur.

Money is stored and transported as an integer count of minor units end to end;
the conversion to a displayable string happens once, at the view boundary.

The public API never exposes `cost_price` or exact stock — margin and sales
velocity are not public data. Both appear only when an admin guard resolves.

## What "fully dynamic" means here

None of the following is hardcoded anywhere in the frontend. All of it is
served by the Laravel settings API and editable at runtime from the admin
panel:

company name · logo · light/dark logo · favicon · brand description · brand
colours · button colour · border radius · font family · website title · meta
tags · Google Analytics and Facebook Pixel IDs · phone · email · address ·
Google Maps URL · social links · currency and symbol · tax and VAT · order and
invoice prefixes · feature flags · menus · footer

Adding a new brandable field is an `INSERT`, not a migration and a deploy.

Environment variables hold secrets and infrastructure addresses only. Business
branding is data — see [docs/settings.md](docs/settings.md).

## Stack

| Tier | Technology |
|---|---|
| Backend | Laravel 12, PHP 8.3, Sanctum, MySQL 8 / PostgreSQL 16, Redis 7 |
| Frontend | Next.js 16 (App Router), TypeScript, Tailwind v4, TanStack Query, Zustand, React Hook Form, Zod |
| Admin | Laravel Blade, session auth, shares the API's service layer |
| Infra | Docker, Nginx, Redis, MinIO (S3-compatible), Mailpit |

## Quick start

```bash
cp .env.example .env
cp backend/.env.example backend/.env
cp frontend/.env.example frontend/.env.local
# Set a matching secret in both:
#   backend/.env   FRONTEND_REVALIDATION_SECRET=<16+ chars>
#   frontend/.env.local  REVALIDATION_SECRET=<same value>

docker compose up -d --build
docker compose exec php composer install
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate --seed
docker compose exec php php artisan storage:link
```

The seeder prints the generated Super Admin password **once** — copy it before
the output scrolls away. Set `SUPER_ADMIN_PASSWORD` in `backend/.env`
beforehand to choose your own. There is deliberately no hardcoded default.

Open http://localhost:8080 — the homepage renders live settings and a
dependency health panel. Sign in to the panel at
http://localhost:3000/admin/login.

Full instructions and troubleshooting: [docs/setup.md](docs/setup.md).

## Layout

```
├── backend/          Laravel 12 — API v1, Blade admin, domain services
│   ├── app/
│   │   ├── Enums/          Backed enums used as model casts
│   │   ├── Events/         Listeners/  Jobs/  Notifications/
│   │   ├── Http/           Controllers/{Api\V1,Admin}  Middleware  Requests  Resources
│   │   ├── Services/       Business logic — the API and admin share these
│   │   └── Traits/         ApiResponse envelope
│   ├── routes/api/v1.php   Versioned routes
│   └── tests/
├── frontend/         Next.js 16 — feature-based
│   └── src/
│       ├── app/            Routing only; delegates to features
│       ├── features/       settings/, health/ — each owns api/hooks/types
│       ├── lib/            api client, env validation, theme, query client
│       └── components/     ui/, layout/, providers/
├── docker/           nginx/ php/ mysql/ redis/
└── docs/             architecture.md · api.md · settings.md · setup.md
```

## Endpoints

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v1/health` | Liveness — probes nothing, for container checks |
| GET | `/api/v1/health/ready` | Readiness — real round-trips to every dependency |
| GET | `/api/v1/settings/public` | Storefront configuration |
| POST | `/api/v1/auth/*` | Customer register, login, logout, password, profile |
| POST | `/api/v1/admin/auth/*` | Staff login, logout, password |
| — | `/api/v1/admin/admins/*` | Staff management, roles, permissions |
| — | `/api/v1/admin/settings/*` | Read, bulk-update, media upload, cache flush |
| — | `/admin/settings` | *(Blade)* Settings management panel |
| POST | `/api/revalidate` | *(Next.js)* Cache purge webhook |

See [docs/api.md](docs/api.md), [docs/settings.md](docs/settings.md), and
[docs/authentication.md](docs/authentication.md).

## Access control

Customers (`users`) and staff (`admins`) are separate tables with separate
guards and password-reset brokers — there is no code path from a customer
record to an administrator. Seven roles carry granular permissions, with
per-admin grant/revoke overrides on top.

Rank levels prevent `manage_admins` from being a blank cheque: nobody may
assign a role at or above their own, grant a permission they do not hold, act
on a peer of equal rank, or remove the last Super Admin.

Full reasoning in [docs/authentication.md](docs/authentication.md).

## Testing

```bash
docker compose exec php php artisan test    # 179 passing, in-memory SQLite

cd frontend
npm run typecheck
npm run lint
npm run build
```

`backend/.env.testing` is committed and holds no secrets. Laravel loads it in
place of `.env` whenever `APP_ENV=testing`, which keeps the suite hermetic:
`docker-compose` injects `backend/.env` into the container as real environment
variables, and without a testing env file the suite would run against the
development Redis and MySQL.

## Design decisions

The reasoning behind the layering, the EAV settings table, the three-database
Redis split, the two-gate public exposure rule, and the invalidation pipeline
is documented in [docs/architecture.md](docs/architecture.md). A few worth
knowing up front:

- **`CACHE_STORE` must be `redis`.** Cache tags silently no-op on the `file`
  driver, which would serve stale branding after an admin change.
- **Redis uses three logical databases** so `cache:clear` cannot destroy live
  sessions or drop queued jobs.
- **Public settings need two gates** — `is_public` *and* a publicly-exposable
  group — so a mistaken toggle on a payment credential cannot leak it.
- **Setting keys contain dots, so validation rules escape them.** A rule keyed
  `settings.theme.primary_color` is read by the validator as nested-array
  traversal: it looks for a nested array, finds nothing, and passes every rule
  silently. `settings.theme\.primary_color` is what actually validates.
- **Brand uploads use a mime allowlist, not Laravel's `image` rule.** `image`
  rejects SVG outright, and logos are commonly SVG. The allowlist is strictly
  narrower — seven named formats against any raster type.
- **CORS origins are explicit.** A wildcard is invalid alongside
  `supports_credentials: true`; browsers reject the combination.
- **The frontend degrades rather than crashes.** If the API is unreachable it
  renders neutral fallbacks and a visible warning, never a stale hardcoded
  brand.
