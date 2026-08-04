# E-commerce Platform

A production-ready, fully dynamic e-commerce platform. Laravel 12 API + Blade
admin panel, Next.js 16 storefront, MySQL, Redis, and S3-compatible storage —
all containerised.

**Phases 1–6 complete:** foundation; authentication and role-based access
control; dynamic store settings, branding, and theme management; product
catalog and inventory; the dynamic homepage builder and CMS; the customer
storefront and cart. Checkout, orders, and payments are later phases.

## Storefront and cart

Browsing, search, faceted filtering, the cart, wishlist, comparison, and the
account area. The load-bearing decision is a negative one:

**No price ever reaches the database from a request, and no price is ever read
back from a cart row.** A client says *what* it wants and *how many*; unit
price, discount, line total, subtotal, tax, and availability are all derived
from the catalog on every read.

That is stricter than validating a submitted price. Validation is a code path
that can be skipped or forgotten on the next endpoint someone adds; deriving the
figure means there is no client-supplied price in the system to check. So
`cart_items` has no price column, and `AddCartItemRequest` has no price field to
submit one into.

Guests and signed-in customers share one `carts` table, keyed by either a token
or a user id — one storage engine, one pricing path, and a merge on sign-in that
is an `UPDATE` rather than a translation between two representations. The guest
credential travels in an `X-Cart-Token` header rather than a cookie the API
sets, because the API is deliberately stateless and an automatically-attached
cookie would reintroduce the CSRF surface that absence exists to avoid.

A cart does **not** reserve stock. Two shoppers can both hold the last unit; the
one who checks out first gets it. Reserving at add-to-cart would let anyone deny
the catalog to everyone else by filling a basket.

Filters, sort, and pagination live in the URL, so a filtered view is shareable,
survives a refresh, responds to the back button, and is rendered on the server.
Product grids and cards stay server components; only the controls — filter rail,
sort, add-to-cart, wishlist and compare toggles, cart drawer — ship JavaScript.

See [docs/storefront.md](docs/storefront.md).

## Dynamic homepage and CMS

The homepage is not a template. It is an ordered list of rows in
`homepage_sections` — hero sliders, promotional banners, featured products, new
arrivals, best sellers, categories, flash sales, hand-picked collections,
testimonials, blog rails, and free-form content blocks. Each carries an enabled
flag, a sort order, and a start and end date. The Next.js page fetches that list
and renders whatever it is given, in the order it is given.

**No homepage business content is hardcoded in the frontend.** Adding a section
is an `INSERT`; reordering the page is an `UPDATE`; scheduling a Black Friday
hero is a date field. An admin drags sections into order, toggles them, and
previews the result *at any chosen moment* — scheduling that can only be
verified by waiting for the scheduled date is scheduling nobody trusts.

Section content is resolved at read time, never snapshotted: a featured rail
stores `{"limit": 8}`, not eight product ids captured at save time. Unpublishing
a product removes it from the homepage without anyone re-saving a section.

Editorial pages — About, Contact, Privacy, Terms, Refunds, Shipping — are rows
in `cms_pages` served at `/p/{slug}`, each with its own title, slug, body,
featured image, SEO metadata, and publish state. The six are seeded as drafts
with visibly unwritten bodies and protected from deletion, but are otherwise
fully editable: a store must be able to write its own refund policy.

Rich text is reduced to a strict allowlist **on write**, so the stored value is
the safe value and no read path can bypass the filter. That an author is an
administrator is not an input filter.

See [docs/content.md](docs/content.md).

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
└── docs/             architecture.md · api.md · settings.md · content.md · storefront.md · setup.md
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
docker compose exec php php artisan test    # in-memory SQLite

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
