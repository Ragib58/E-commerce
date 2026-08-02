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
| `group` | string | Optional. One of `general`, `branding`, `theme`, `contact`, `social`, `seo`, `analytics`, `business`, `feature` |

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
    "general": { "company_name": "Nexus Commerce", "tagline": "...",
                 "locale": "en", "maintenance_mode": false },
    "branding": { "logo": "http://localhost:8080/storage/branding/logo-a1b2.svg",
                  "logo_light": null, "logo_dark": null, "favicon": null,
                  "og_image": null, "brand_description": "..." },
    "theme": { "primary_color": "#2563eb", "secondary_color": "#64748b",
               "accent_color": "#f59e0b", "background_color": "#ffffff",
               "foreground_color": "#0f172a", "button_color": "#2563eb",
               "destructive_color": "#dc2626", "radius": "0.5rem",
               "font_family": "Inter" },
    "contact": { "email": "support@example.com", "phone": "+1 (555) 010-0100",
                 "address": "...", "google_maps_url": null },
    "social": { "facebook": null, "instagram": null, "x": null,
                "linkedin": null, "youtube": null, "tiktok": null },
    "seo": { "website_title": "Nexus Commerce", "meta_title": "...",
             "meta_description": "...", "meta_keywords": "...",
             "indexable": true },
    "analytics": { "google_analytics_id": null, "facebook_pixel_id": null },
    "business": { "currency": "USD", "currency_symbol": "$", "tax_rate": 0,
                  "vat_rate": 0, "order_prefix": "ORD-",
                  "invoice_prefix": "INV-" },
    "feature": { "wishlist_enabled": true }
  },
  "meta": {
    "version": "7",
    "groups": ["general", "branding", "theme", "contact", "social", "seo",
               "analytics", "business", "feature"]
  }
}
```

`meta.version` increments on every settings change. Clients key their cache on
it, so a change produces a new cache key and stale branding cannot be served.

**Why analytics IDs are public.** A GA measurement ID or Facebook Pixel ID is
visible in the page source of every site that uses one — they identify a
property, they do not authenticate to it. They live here rather than in `.env`
so marketing can change them without a deploy.

---

## Admin settings management

Base path `/admin/settings`. Every route requires a valid admin token, an active
account, a current password, and a permission:

| Operation | Permission |
|---|---|
| Read | `view_settings` **or** `manage_settings` |
| Write, upload, flush | `manage_settings` |

Unlike `/settings/public`, this surface exposes **private groups** (`mail`,
`payment`, `shipping`), which is why it is permission-gated rather than merely
authenticated.

### `GET /admin/settings`

Every setting, grouped, with the metadata needed to render an edit form —
label, description, type, lock flag. Narrow with `?group=theme`.

```jsonc
{
  "data": {
    "theme": {
      "label": "Theme & Colours",
      "description": "Brand and theme colours applied to the storefront.",
      "icon": "swatch",
      "is_public": true,
      "settings": [
        { "key": "theme.primary_color", "value": "#2563eb", "type": "color",
          "type_label": "Colour", "label": "Primary Colour",
          "description": "Buttons, links, and primary actions.",
          "is_public": true, "is_locked": true, "sort_order": 1,
          "updated_at": "2026-08-02T10:00:00+00:00" }
      ]
    }
  },
  "meta": { "version": "7", "groups": ["theme"] }
}
```

File-backed settings carry an extra `path` field (the stored disk path) beside
the resolved absolute `value`.

### `GET /admin/settings/groups`

The full group catalogue, for building a tab strip without hardcoding the list.

### `PUT /admin/settings`

Bulk update. Validation rules are derived from each setting's declared `type`,
so a setting added at runtime is validated correctly with no code change.

```json
{ "settings": { "theme.primary_color": "#7c3aed",
                "general.company_name": "Aurora Supply" } }
```

The **whole** submission is validated before anything is written — a single
invalid colour cannot leave a half-applied theme. Unknown keys are rejected
(`422`) rather than created; accepting them would turn this into an arbitrary
settings-row factory, including rows in publicly exposed groups.

### `POST /admin/settings/media/{key}`

`multipart/form-data`, field `file`. Uploads a brand asset and points `{key}` at
it, deleting any previous file. Rejected with `422` if `{key}` is not an
image-typed setting — otherwise an upload could store a file path where the
storefront expects a hex colour.

Accepts `jpg, jpeg, png, webp, gif, svg, ico` up to `UPLOAD_MAX_IMAGE_KB`
(default 4 MB). SVG is admitted deliberately: logos are commonly SVG, and
Laravel's `image` rule alone would reject it.

```json
{ "data": { "key": "branding.logo",
            "url": "http://localhost:8080/storage/branding/logo-a1b2c3.svg" } }
```

Typical keys: `branding.logo`, `branding.logo_light`, `branding.logo_dark`,
`branding.favicon`, `branding.og_image`.

### `DELETE /admin/settings/media/{key}`

Clears the value and deletes the file. The row survives with `value = null` —
the key is part of the seeded schema and the admin form still renders its field.

### `POST /admin/settings/cache/flush`

Drops every cached settings payload, bumps the version stamp, and revalidates
the storefront. An escape hatch for when the table has been written to outside
the service (a direct SQL fix, a restored backup).

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
