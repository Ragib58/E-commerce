# API Reference — v1

Base URL: `http://localhost:8080/api/v1`

## Conventions

Every endpoint returns the same envelope. Branch on `success`; the HTTP status
is supplementary.

```jsonc
// success
{ "success": true, "message": "...", "data": {...}, "meta": {...} }

// failure
{ "success": false, "message": "...", "code": "VALIDATION_FAILED",
  "errors": { "field": ["message"] } }
```

### Response headers

| Header | Purpose |
|---|---|
| `X-API-Version` | Version that answered (`v1`) |
| `X-API-Supported-Versions` | All active versions |
| `X-Request-Id` | Correlation id; echoed if the client supplies one |

### Error codes

| Code | Status | Meaning |
|---|---|---|
| `VALIDATION_FAILED` | 422 | Input rejected; see `errors` |
| `UNAUTHENTICATED` | 401 | Missing or invalid credentials |
| `FORBIDDEN` | 403 | Authenticated but not permitted |
| `RESOURCE_NOT_FOUND` | 404 | Model does not exist |
| `ENDPOINT_NOT_FOUND` | 404 | No such route in this version |
| `METHOD_NOT_ALLOWED` | 405 | Wrong HTTP verb |
| `RATE_LIMITED` | 429 | Throttled; see `Retry-After` |
| `SERVER_ERROR` | 500 | Unhandled failure |

### Rate limits

| Limiter | Default | Applies to |
|---|---|---|
| `public` | 60/min per IP | Storefront reads |
| `authenticated` | 120/min per user | Authenticated requests |
| `health` | 30/min per IP | Health probes |

Health has a separate budget so a traffic spike never blinds monitoring, and
monitoring never consumes the public budget.

---

## `GET /health`

Liveness. Deliberately probes nothing — it answers "is the PHP process
responding?". Use this for container `HEALTHCHECK` and load-balancer checks.

A liveness probe that checks dependencies causes restart storms: the app gets
killed because the database is slow, which does not fix the database.

```json
{
  "success": true,
  "message": "Service is alive.",
  "data": { "status": "ok", "timestamp": "2026-08-01T12:00:00+00:00" }
}
```

Always `200` while the process is up.

---

## `GET /health/ready`

Readiness. Probes every dependency with a real round-trip — a query, a
cache SET/GET, a storage write — because a live TCP connection to a database
whose disk is full still reports "connected".

**Status codes:** `200` when `ok` or `degraded`, `503` when `down`.

Dependencies are classified critical or optional. A failing critical
dependency drives the aggregate to `down` so an orchestrator drains the
instance; a failing optional one only degrades it, because taking a service
down over a flaky object store is worse than serving.

| Dependency | Critical | Probe |
|---|---|---|
| `database` | yes | `SELECT 1` |
| `cache` | yes | write / read / compare / delete |
| `redis` | yes | `PING` |
| `queue` | no | connection reachable + pending depth |
| `storage` | no | write / read / compare / delete |

```json
{
  "success": true,
  "message": "All systems operational.",
  "data": {
    "status": "ok",
    "checks": {
      "database": { "status": "ok", "critical": true, "latency_ms": 1.42,
                    "message": null, "details": { "driver": "mysql" } },
      "queue":    { "status": "ok", "critical": false, "latency_ms": 0.9,
                    "message": null, "details": { "pending": 0 } }
    }
  },
  "meta": {
    "environment": "local", "php_version": "8.3.x",
    "laravel_version": "12.x", "duration_ms": 12.4
  }
}
```

`message` carries the exception text only when `APP_DEBUG` is on — driver
errors routinely embed credentials.

---

## `GET /settings/public`

The endpoint that makes the storefront dynamic. Returns admin-managed
configuration: company name, logo, favicon, brand colours, contact details,
social links, SEO defaults, and feature flags.

**Query parameters**

| Name | Type | Notes |
|---|---|---|
| `group` | string | Optional. One of `general`, `branding`, `theme`, `contact`, `social`, `seo`, `feature` |

Requesting a non-public group (`payment`, `shipping`, `mail`) returns `422`
rather than an empty result — a clearer contract, and one less way to probe
which private groups exist.

**Exposure rules.** A setting is returned only if `is_public = true` **and**
its group is publicly exposable. Both gates must pass, so a mistaken
`is_public` toggle on a payment credential still cannot leak it.

Keys are returned without their group prefix: `branding.logo` becomes
`data.branding.logo`. Values are cast to their declared type — booleans are
real booleans, not `"1"`. Image settings are expanded to absolute URLs.

```json
{
  "success": true,
  "message": "Settings retrieved successfully.",
  "data": {
    "general": { "company_name": "Nexus Commerce", "currency": "USD",
                 "maintenance_mode": false },
    "branding": { "logo": "http://localhost:8080/storage/branding/logo.svg",
                  "favicon": null },
    "theme": { "primary_color": "#2563eb", "radius": "0.5rem" },
    "contact": { "email": "support@example.com" },
    "social": { "instagram": null },
    "seo": { "meta_title": "...", "indexable": true },
    "feature": { "wishlist_enabled": true }
  },
  "meta": {
    "version": "7",
    "groups": ["general", "branding", "theme", "contact", "social", "seo", "feature"]
  }
}
```

`meta.version` increments on every settings change. Clients key their cache on
it, so a change produces a new cache key and stale branding cannot be served.

---

## Frontend webhook

### `POST /api/revalidate`

Served by **Next.js**, not Laravel. Called by the `RevalidateFrontendCache`
job when admin content changes.

**Headers:** `X-Revalidation-Secret: <shared secret>`

```json
{ "tags": ["settings"], "keys": ["general.company_name"] }
```

Authentication is a timing-safe comparison over SHA-256 digests, so neither the
secret's length nor its prefix leaks through response timing. An unset secret
returns `503` — it fails closed, never "allow everyone". Only known tags are
honoured; unknown tags are logged and ignored so a compromised caller cannot
purge arbitrary cache entries.

| Status | Meaning |
|---|---|
| `200` | Revalidated |
| `400` | Malformed JSON |
| `401` | Invalid secret |
| `422` | Invalid payload |
| `503` | `REVALIDATION_SECRET` not configured |

---

---

## Authentication

Two realms with separate guards, tables, and reset brokers. See
[authentication.md](authentication.md) for the reasoning.

Authenticated requests send `Authorization: Bearer <token>`.

### Customer — `/auth/*`

| Method | Path | Auth | Notes |
|---|---|---|---|
| POST | `/auth/register` | — | Returns a token immediately; account starts unverified |
| POST | `/auth/login` | — | |
| POST | `/auth/forgot-password` | — | Always 200, even for unknown addresses |
| POST | `/auth/reset-password` | — | Revokes **every** session |
| GET | `/auth/verify-email/{id}/{hash}` | signed URL | Redirects to the storefront |
| POST | `/auth/email/resend` | token | Works while unverified |
| GET | `/auth/email/status` | token | |
| GET | `/auth/me` | token | |
| POST | `/auth/logout` | token | Current token only |
| POST | `/auth/logout-all` | token | |
| PATCH | `/auth/profile` | token + verified | |
| POST | `/auth/change-password` | token + verified | Revokes other sessions |

### Admin — `/admin/auth/*`

There is deliberately **no registration endpoint**. Staff accounts are created
only by an existing administrator holding `manage_admins`.

| Method | Path | Auth |
|---|---|---|
| POST | `/admin/auth/login` | — |
| POST | `/admin/auth/forgot-password` | — |
| POST | `/admin/auth/reset-password` | — |
| GET | `/admin/auth/me` | token |
| POST | `/admin/auth/logout` | token |
| POST | `/admin/auth/logout-all` | token |
| POST | `/admin/auth/change-password` | token |

The login and `/me` responses include a `permissions` array — the account's
effective permission set, used by the panel to hide navigation it cannot use.

### Admin management — `/admin/admins/*`

Every route requires a valid admin token, an active account, a current
password, and the listed permission. Records are addressed by **uuid**.

| Method | Path | Permission |
|---|---|---|
| GET | `/admin/admins` | `view_admins` or `manage_admins` |
| POST | `/admin/admins` | `manage_admins` |
| GET | `/admin/admins/{uuid}` | `view_admins` or `manage_admins` |
| PATCH | `/admin/admins/{uuid}` | `manage_admins` |
| DELETE | `/admin/admins/{uuid}` | `manage_admins` |
| PATCH | `/admin/admins/{uuid}/status` | `manage_admins` |
| PUT | `/admin/admins/{uuid}/roles` | `manage_roles` |
| PUT | `/admin/admins/{uuid}/permissions` | `manage_roles` |
| GET | `/admin/roles` | `manage_roles` or `view_admins` |
| GET | `/admin/roles/{name}` | `manage_roles` or `view_admins` |
| GET | `/admin/permissions` | `manage_roles` or `view_admins` |

`GET /admin/roles` returns only roles the caller may actually assign, so the UI
cannot offer an option the API would reject.

Omitting `password` on create generates one, returns it **once**, and forces
rotation at first sign-in.

### Auth error codes

| Code | Status | Meaning |
|---|---|---|
| `ADMIN_AUTH_REQUIRED` | 401 | Not an authenticated administrator |
| `INSUFFICIENT_PERMISSIONS` | 403 | Includes `required_permissions` |
| `INSUFFICIENT_ROLE` | 403 | |
| `ACCOUNT_DEACTIVATED` | 403 | Token destroyed; sign in again |
| `EMAIL_NOT_VERIFIED` | 403 | Prompt the user to verify |
| `PASSWORD_CHANGE_REQUIRED` | 403 | Forced rotation outstanding |
| `INVALID_TOKEN_ABILITY` | 403 | Token is for the other realm |

---

## Adding v2

1. Create `backend/routes/api/v2.php`.
2. Add `'v2'` to `supported_versions` in `config/api.php`.

`routes/api.php` iterates that list, so v1 routes are untouched.
