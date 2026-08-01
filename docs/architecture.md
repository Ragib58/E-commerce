# Architecture

## System overview

Three deployable units behind one Nginx edge, sharing a Docker network.

```
                    ┌──────────────────────────┐
                    │      Nginx (edge :80)    │
                    └────┬────────────────┬────┘
              /api/*  ───┘                └───  /*
              /admin/*
              /storage/*
                    │                            │
        ┌───────────▼───────────┐    ┌───────────▼───────────┐
        │  Laravel 12 (PHP-FPM) │    │  Next.js 16 (node)    │
        │  API v1 + Admin Panel │◄───┤  App Router / RSC     │
        └──┬─────┬─────┬────────┘    └───────────────────────┘
           │     │     │
     ┌─────▼─┐ ┌─▼───┐ ┌▼──────┐  ┌──────────────┐
     │ MySQL │ │Redis│ │ MinIO │  │ queue worker │
     └───────┘ └─────┘ └───────┘  │ scheduler    │
                                  └──────────────┘
```

## The contract between tiers

The frontend never touches the database, never reads a config file for
branding, and holds no business logic. Company name, logo, favicon, palette,
contact details, and social links all arrive from
`GET /api/v1/settings/public`. Laravel is the single source of truth; Next.js
is a rendering client with a cache.

**Why the admin panel is Blade rather than a third SPA.** Server-side sessions
mean no token-refresh dance and no CORS surface on privileged routes. More
importantly, admin controllers call the *same* `App\Services` classes the API
does — so a settings change made from the panel and one made from a future API
call take an identical code path, including cache invalidation. Two consumers,
one domain layer, zero duplicated business rules.

## How a settings change propagates

This is the mechanism behind "everything is dynamic":

```
Admin saves in Blade panel
  └─> SettingsService::set()
        ├─> DB write (transaction, lockForUpdate)
        ├─> Cache::tags(['settings'])->flush()
        └─> SettingsUpdated event
              └─> InvalidateFrontendCache listener
                    ├─> bumps settings:version   (synchronous, cheap)
                    └─> RevalidateFrontendCache job  (queued)
                          └─> POST /api/revalidate  (shared secret)
                                └─> revalidateTag('settings', 'max')
                                      └─> next visitor sees the new value
```

No redeploy, no rebuild. The HTTP call is queued with retries so a frontend
that is mid-deploy does not block an administrator's save, and a lost
invalidation still self-corrects when the ISR window lapses.

## Backend layering

```
Route (v1) → Middleware → FormRequest → Controller → Service → Model
                                             ↓
                                   Event → Listener → Job → Notification
                                             ↓
                                         ApiResource
```

Controllers do exactly three things: call a service, return a resource, and
nothing else. No queries, no business conditionals, no `DB::transaction` —
services own transactions. That discipline is what keeps the API and the admin
panel genuinely sharing logic rather than drifting into two implementations.

### Structural pieces

| Component | Responsibility |
|---|---|
| `app/Enums` | Backed enums used as model casts — invalid values impossible at the model layer, not merely validated at the edge |
| `app/Traits/ApiResponse` | The single response envelope, success and failure |
| `app/Exceptions/ApiExceptionHandler` | Maps every throwable onto that envelope; forces JSON on `api/*` |
| `app/Http/Middleware/RequestId` | Correlation id across Nginx → Laravel → logs |
| `app/Http/Middleware/ApiVersion` | Resolves and stamps the active version |
| `app/Services` | Business logic, transactions, cache invalidation |

### Response envelope

```jsonc
// success
{ "success": true, "message": "...", "data": {...}, "meta": {...} }

// failure
{ "success": false, "message": "...", "code": "VALIDATION_FAILED",
  "errors": { "field": ["..."] } }
```

Consumers branch on `success` alone and never need to inspect the HTTP status
to know whether a payload is present.

## Frontend architecture

Feature-based, not type-based. Each `src/features/<domain>/` owns its `api/`,
`components/`, `hooks/`, and `types/`.

Three rules that shape everything:

**One typed API client.** `lib/api/client.ts` wraps `fetch` with the base URL,
timeout via `AbortSignal`, envelope unwrapping, and a typed `ApiError`. No
component calls `fetch` directly. That concentration is why adding auth headers
later is a one-file change.

**Server components fetch; client components subscribe.** Branding is fetched
in the root layout (an RSC) so the first paint already carries the right logo
and title — no flash of default theme, and the real title is in the HTML for
crawlers. Interactive data uses TanStack Query.

**Theming through CSS custom properties.** Admin hex colours are converted to
bare HSL triples and written into a `<style>` block at request time. Tailwind
consumes `hsl(var(--primary))`, so `bg-primary/50` still composes an alpha
channel. Dynamic brand colours with no runtime CSS-in-JS and no Tailwind
rebuild.

Zustand is reserved for genuinely client-owned state (cart drawer, UI toggles).
Server data belongs to TanStack Query.

## Database strategy

MySQL 8.0, `utf8mb4_unicode_ci`, InnoDB — and PostgreSQL-compatible throughout.
No engine-specific column types and no raw SQL, so `DB_CONNECTION=pgsql` runs
the same migrations unchanged. The test suite proves this: it runs on SQLite.

**Settings are EAV, deliberately.** `settings(key, value, type, group,
is_public)` with a `type` enum driving casts on read. Adding a brandable field
is an INSERT from the admin panel, not a migration and a deploy. The cost is no
FK integrity on values — the right trade for admin-authored config, and the
wrong one for transactional data, which will get real columns.

**Enums stored as strings, not native DB enums.** Adding a case must not
require an `ALTER TABLE`, and the constraint is enforced identically across
MySQL and PostgreSQL by the model cast.

## API strategy

**URI versioning** (`/api/v1`) over header negotiation: cacheable by Nginx and
CDNs without a `Vary` header, debuggable in a browser, unambiguous in logs.
`routes/api.php` is a loader that iterates `config('api.supported_versions')`,
so v2 is a new file plus one config line — zero churn to v1.

Rate limiters are named and defined in `RouteServiceProvider`, with the numbers
in `config/api.php` so they are tunable per environment. Health probes get
their own budget so a traffic spike never blinds monitoring.

Every response carries `X-API-Version`, `X-API-Supported-Versions`, and
`X-Request-Id`.

## Caching strategy

Redis with three logical databases. This separation is operational, not
cosmetic: `php artisan cache:clear` issues `FLUSHDB` against the cache
connection only, so it can never destroy live admin sessions or drop queued
jobs.

| DB | Purpose | Survives `cache:clear` |
|----|---------|------------------------|
| 0 | Cache (tagged, volatile) | no — by design |
| 1 | Sessions | yes |
| 2 | Queues | yes |

| Layer | Mechanism | TTL | Invalidation |
|---|---|---|---|
| Laravel settings | `Cache::tags(['settings'])` | 24h | Event-driven on write |
| Next.js RSC | `next: { tags: ['settings'] }` | 300s | `revalidateTag` webhook |
| TanStack Query | `staleTime` | 5min | Mutation invalidation |
| Nginx static | `expires` | 1y | Content-hashed filenames |

The long TTLs are backstops. Invalidation is event-driven; the window only
bounds staleness if a webhook is lost.

**Tags require Redis or Memcached.** They silently no-op on the `file` driver,
which would serve stale branding after a change — this is why `.env.example`
pins `CACHE_STORE=redis` and why `SettingsService` checks driver support before
reaching for tags.

`maxmemory-policy` is `noeviction`, not `allkeys-lru`: sessions and queued jobs
share the instance with the cache, and silently evicting a job to make room for
a cache entry is data loss. Cache entries carry TTLs and expire on their own.

## Security decisions worth noting

- **Two gates on public settings.** A setting must have `is_public = true`
  *and* belong to a publicly-exposable group. A mistaken toggle on a payment
  credential still cannot leak it. There is a test for exactly this.
- **CORS origins are explicit, never wildcarded.** `supports_credentials: true`
  and `allowed_origins: ['*']` are mutually incompatible under the CORS spec —
  browsers reject the combination outright.
- **Revalidation fails closed.** An unset `REVALIDATION_SECRET` returns 503,
  never "allow everyone". The comparison is timing-safe over SHA-256 digests,
  so neither the secret's length nor its prefix leaks through response timing.
- **Theme CSS is allowlisted.** Colours are reconstructed from parsed integers,
  but `radius` and `font_family` are free-text admin inputs injected into a
  `<style>` block — both are validated against strict patterns, because a value
  containing `}` could otherwise close the rule and inject arbitrary CSS.
- **Exception detail is debug-gated.** Driver messages routinely embed
  connection strings; health probes and the exception handler both return a
  generic message unless `APP_DEBUG` is on.

## Deliberately deferred

Authentication, products, orders, payments, and the admin CRUD modules are
later phases. The seams exist now — guards configured, `routes/admin.php`
mounted, `User` model and Sanctum table present, service layer in place — so
those phases add files rather than restructure these.
