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

## Public catalog

Unauthenticated and read-only. Every query is constrained to **published**
records server-side, so no parameter can surface a draft — a draft slug returns
404, deliberately indistinguishable from one that never existed, so the
unreleased catalog cannot be enumerated.

Records are addressed by **slug**; products additionally carry a public `uuid`
as their `id`. The integer primary key is never exposed.

| Method | Path | Notes |
|---|---|---|
| GET | `/products` | Filtered, sorted, paginated listing |
| GET | `/products/{slug}` | Includes variants, media, related, breadcrumbs |
| GET | `/categories` | The published tree, nested |
| GET | `/categories/{slug}` | The category plus its products |
| GET | `/brands` | |
| GET | `/brands/{slug}` | The brand plus its products |
| GET | `/catalog/filters` | Attributes, brands, price bounds, sort keys |
| GET | `/catalog/rails/{rail}` | `featured`, `new_arrivals`, or `best_sellers` |

### Listing parameters

| Parameter | Example | Notes |
|---|---|---|
| `search` | `?search=cotton` | Name, SKU, slug, short description |
| `category` | `?category=clothing` | **Includes descendants** |
| `brand` | `?brand[]=northwind,contoso` | Comma-separated |
| `min_price`, `max_price` | `?min_price=1000` | Minor units, against list price |
| `attributes[slug]` | `?attributes[colour]=red,blue` | Values OR; attributes AND |
| `in_stock` | `?in_stock=1` | |
| `sort` | `?sort=price_asc` | Allowlisted; unknown falls back to default |
| `per_page` | `?per_page=24` | Capped server-side |

Sorts are an allowlist mapped to indexed columns, so an unrecognised value
cannot reach the `ORDER BY`. Every sort carries an `id` tiebreaker, without
which rows sharing a sort value could appear twice — or never — while paging.

### What the public API never returns

`cost_price` and exact `stock` are omitted unless an admin guard resolves.
Publishing cost price gives away the margin on every product; publishing exact
stock lets a competitor meter sales precisely. The storefront receives
`in_stock` and `low_stock` booleans instead.

---

## Admin catalog

Every route requires a valid admin token, an active account, a current
password, and the listed permission. Records are addressed by **id** or, for
products and variants, **uuid** — never by slug, since an admin editing a slug
would invalidate the URL they are editing from.

Product permissions are split four ways because the roles differ: a
merchandiser edits copy and pricing all day, while deleting a product withdraws
something with order history behind it.

| Method | Path | Permission |
|---|---|---|
| GET | `/admin/products` | `view_products` |
| POST | `/admin/products` | `create_products` |
| GET | `/admin/products/{id}` | `view_products` |
| PATCH | `/admin/products/{id}` | `update_products` |
| DELETE | `/admin/products/{id}` | `delete_products` |
| POST | `/admin/products/{id}/restore` | `delete_products` |
| PATCH | `/admin/products/{id}/status` | `update_products` |
| POST | `/admin/products/bulk` | `update_products` (+`delete_products` to delete) |
| POST | `/admin/products/{id}/media` | `update_products` |
| PUT | `/admin/products/{id}/media/reorder` | `update_products` |
| PATCH | `/admin/products/{id}/media/{media}/thumbnail` | `update_products` |
| DELETE | `/admin/products/{id}/media/{media}` | `update_products` |
| GET/POST | `/admin/products/{id}/variants` | `view_products` / `update_products` |
| POST | `/admin/products/{id}/variants/generate` | `update_products` |
| PATCH/DELETE | `/admin/variants/{uuid}` | `update_products` |
| GET/POST | `/admin/categories` | `view_categories` … / `manage_categories` |
| PATCH/DELETE | `/admin/categories/{id}` | `manage_categories` |
| PUT | `/admin/categories/reorder` | `manage_categories` |
| GET/POST | `/admin/brands` | `view_brands` … / `manage_brands` |
| PATCH/DELETE | `/admin/brands/{id}` | `manage_brands` |
| GET/POST | `/admin/attributes` | `view_products` / `create_products` |
| POST/DELETE | `/admin/attributes/{id}/values` | `update_products` |

Deleting a non-empty category or brand is refused unless `?cascade=1` is
passed, so re-homing children and uncategorising products is always explicit.
Products are only ever soft-deleted — the stock ledger holds foreign keys to
them, and the catalog record is evidence of what was sold.

`POST /admin/products/{id}/variants/generate` takes attribute value ids grouped
by attribute and builds the cartesian product, skipping combinations that
already exist — so it is safe to re-run after adding a colour.

---

## Inventory

Stock is gated on `update_products` rather than a catalog-authoring permission,
so a warehouse account can record counts without being able to create or delete
products.

| Method | Path | Permission |
|---|---|---|
| POST | `/admin/products/{id}/stock` | `update_products` |
| GET | `/admin/products/{id}/stock/history` | `view_products` |
| GET | `/admin/inventory/movements` | `view_products` |
| GET | `/admin/inventory/alerts` | `view_products` |
| GET | `/admin/inventory/summary` | `view_products` |

### `POST /admin/products/{id}/stock`

```json
{
  "mode": "delta",
  "quantity": 25,
  "reason": "restock",
  "variant_id": null,
  "note": "Supplier invoice 4471"
}
```

`mode` is the field that matters. `delta` applies a signed change; `absolute`
asserts a counted figure and derives the delta server-side, inside the lock.
Conflating them is how stock takes go wrong — an operator who counts 40 and
submits it as a delta *adds* 40 to a figure they had just proved wrong.

`reason` is restricted to manually selectable values. `sale` and `return` are
written only by the order pipeline; accepting them here would let a manual
entry masquerade as a sale and corrupt reconciliation against actual orders.

A variable product holds no stock of its own, so an adjustment against one
without `variant_id` is refused rather than guessed at.

Every response carries the resulting `stock` and the full movement row, so a
panel can render the change it just made without refetching the ledger.

---

## Shopping cart

Open to guests and signed-in customers alike. There is no `auth` middleware: the
cart *is* the authorization boundary, and a request can only act on the cart its
bearer token or `X-Cart-Token` header resolves to. Full reasoning in
[storefront.md](storefront.md).

**No endpoint here accepts a price.** Unit price, discount, line total,
subtotal, and tax are recomputed from the catalog on every call. A `price` in a
request body is discarded before it reaches the service — there is no rule
rejecting it because there is no field to submit it into.

### The guest credential

An anonymous visitor's first cart mutation mints a token, returned in the
`X-Cart-Token` **response** header. The client stores it and sends it back on
subsequent requests in the `X-Cart-Token` **request** header.

Not a cookie: the API is stateless by design, and a cookie the browser attaches
automatically would reintroduce a CSRF surface. The header must be listed in
CORS `exposed_headers` — without that the browser hides it and every guest
request creates a fresh empty cart.

Resolution rules:

| Request | Resolves to |
|---|---|
| Authenticated | That user's cart, **always** — a supplied token is ignored |
| Guest, valid token | The guest cart with that token, only while `user_id` is null |
| Guest, no token | A new cart — but only on an unsafe method, so a `GET` never inserts a row |

### `GET /cart`

Returns an empty structure rather than `404` when none exists: a shopper who
has never added anything has an empty cart, not a missing one.

```json
{
  "data": {
    "id": 12,
    "items": [
      {
        "id": 34,
        "quantity": 2,
        "product": { "id": "uuid", "name": "…", "slug": "…", "thumbnail": "…", "type": "simple" },
        "variant": { "id": "uuid", "name": "Medium / Red" },
        "unit_price": 2500,
        "list_price": 3000,
        "line_total": 5000,
        "line_discount": 1000,
        "is_available": true,
        "max_quantity": 8,
        "issues": []
      }
    ],
    "item_count": 2,
    "totals": { "subtotal": 5000, "discount": 1000, "tax": 500, "shipping": null, "total": 5500 },
    "coupon": { "code": null, "applied": false, "discount": 0, "message": null },
    "has_issues": false
  }
}
```

`shipping` is `null`, not `0`: it depends on a delivery address the cart does
not have, and a placeholder that changes at checkout reads as a hidden cost.

`max_quantity` is `null` for products that are not stock-tracked — a digital
download, or one on backorder — which is deliberately distinct from `0`.

### Line issues

A line that can no longer be sold is **returned and flagged**, not dropped. A
shopper whose item vanished with no explanation assumes the site lost it.
Flagged lines are excluded from the totals.

| Code | Meaning |
|---|---|
| `UNAVAILABLE` | The product is no longer published |
| `VARIANT_UNAVAILABLE` | The chosen option was deactivated |
| `OUT_OF_STOCK` | Nothing left, and backorder is off |
| `INSUFFICIENT_STOCK` | Fewer remain than the line holds; carries `available` |

### Mutations

| Method | Path | Notes |
|---|---|---|
| `POST` | `/cart/items` | `{ product, variant?, quantity?, options? }` |
| `PATCH` | `/cart/items/{item}` | `{ quantity }` — **0 removes the line** |
| `DELETE` | `/cart/items/{item}` | |
| `DELETE` | `/cart` | Empty the cart |
| `POST` | `/cart/coupon` | `{ coupon_code }` — stored, never discounted |
| `POST` | `/cart/merge` | Claim a guest cart; authenticated only |

Every one returns the **whole recomputed cart**. A quantity change moves the
subtotal, the tax, and possibly another line's availability, so returning one
item would force the client to refetch or recompute — and a client that computes
totals is a client whose totals can be wrong.

`product` accepts a slug or a uuid. `variant` is required for variable products
and refused for the rest, and is scoped to the named product — a variant id from
a different product cannot be attached to a cheaper one.

Item ids are scoped to the requesting cart, so an id from someone else's basket
is a `422` rather than a successful mutation of a stranger's cart.

### `POST /cart/coupon` — placeholder

The code is stored and echoed back with `applied: false` and an explanatory
message. Promotions are a later phase; reporting a zero discount as "applied"
would render as a broken promotion rather than an unbuilt feature. Storing it
means a shopper arriving with a code does not lose it.

### `POST /cart/merge`

Sums quantities for the same `(product, variant)`, re-clamps to available stock,
deletes the guest row, and clears its token. Idempotent, so a client calling it
on every page load cannot double a quantity. Returns `401` for an
unauthenticated caller.

### Rate limiting

Cart mutations use the `cart` limiter (`API_RATE_LIMIT_CART`, default 60/min)
rather than the public read budget — they are writes available to
unauthenticated visitors. Keyed on the user, else the cart token, else the IP.

---

## Wishlist

Authenticated only, unlike the cart. A guest's saved items live in localStorage
and are merged here on sign-in — a server-side anonymous wishlist costs what a
cart does while being worth far less, since the shopper cannot return to it from
another device.

| Method | Path | Notes |
|---|---|---|
| `GET` | `/wishlist` | Returns **products**, so the client renders its usual card |
| `POST` | `/wishlist` | `{ product }` — idempotent |
| `DELETE` | `/wishlist/{product}` | |
| `POST` | `/wishlist/merge` | `{ products: [...] }` — skips unknown ids |

Write responses return `{ saved: [...uuid] }`, the full set rather than the one
that changed, so a client replaces its notion of what is saved wholesale instead
of patching it.

Unpublished products are omitted from the listing rather than flagged. Unlike a
cart line — where a shopper needs to know why their total changed — a saved item
that is no longer for sale is simply not shown, and reappears if republished.

`merge` skips identifiers that do not resolve rather than failing, so one stale
localStorage entry cannot cost a shopper the rest of their list.

---

## `POST /catalog/products/lookup`

Resolves a list of product identifiers to full products, **in the order asked
for**. Serves the compare tray and the recently-viewed rail, both of which hold
their list on the client.

```json
{ "products": ["uuid-or-slug", "…"] }
```

`POST` despite being a read: twenty-plus uuids overflow practical URL length
limits, and a truncated query string would silently drop products rather than
failing visibly. Capped at 24. Identifiers that no longer resolve drop out
rather than leaving a hole the client must handle.

Open to guests.

---

## Storefront content

The dynamic homepage, banners, and CMS pages. Unauthenticated and read-only;
every query is constrained to *live* records — a publishable status **and** an
open scheduling window — so no parameter can surface a draft page or a campaign
that has not launched. Full reasoning in [content.md](content.md).

### `GET /homepage`

The entire homepage in one response: every live section, in display order, with
its products, categories, or banners already resolved. One request rather than
one per section — a six-rail page would otherwise open with a six-deep waterfall
on every cold visit.

```json
{
  "success": true,
  "data": [
    {
      "id": 3,
      "type": "featured_products",
      "name": "Featured products",
      "heading": "Featured",
      "subheading": "Hand-picked from the catalog",
      "settings": { "limit": 8, "columns": 4, "show_view_all": true },
      "style": { "background_color": null, "container_width": "default" },
      "sort_order": 20,
      "starts_at": null,
      "ends_at": null,
      "items": [ /* ProductResource objects */ ],
      "has_content": true
    }
  ],
  "meta": { "section_count": 1, "is_configured": true }
}
```

`items` is heterogeneous — products, banners, categories, or testimonials
depending on `type`. `meta.is_configured` distinguishes "no sections yet" from
"the request failed", which produce an identical empty array but call for very
different UI.

A section whose content resolves to nothing is omitted entirely. Resolved id
lists are stripped from `settings`: they have already become `items`.

### `GET /banners`

| Parameter | Notes |
|---|---|
| `placement` | One of `hero_slider`, `homepage_promo`, `category_top`, `sidebar`, `checkout`, `popup`. An unknown value returns `422` rather than being ignored. |
| `limit` | Clamped to 24. |

The public payload omits `status`, `starts_at`, and `ends_at`: the endpoint only
ever returns live banners, so there is nothing for a client to filter.

### `GET /pages`

Published pages as titles and slugs, for footer navigation. **Bodies are
omitted** — a footer needs six links, and sending six full policy documents to
render them would dominate the payload of every page on the site.

### `GET /pages/{slug}`

One page, with its body and SEO block. A draft, scheduled, or expired page
returns `404` — deliberately indistinguishable from a slug that never existed.

```json
{
  "data": {
    "title": "Refund Policy",
    "slug": "refund-policy",
    "content": "<p>Sanitised HTML.</p>",
    "featured_image": "https://…",
    "seo": {
      "title": "Returns and refunds",
      "description": "…",
      "og_image": "https://…",
      "indexable": true
    },
    "published_at": "2026-05-02T09:00:00+00:00"
  }
}
```

`seo.title` falls back to the page title, and `og_image` to the featured image —
an empty tag is worse than a derived one.

| Code | Meaning |
|---|---|
| `PAGE_NOT_FOUND` | No live page at this slug |
| `INVALID_PLACEMENT` | Unknown banner placement |

---

## Admin content management

Gated on `manage_content` (homepage structure, pages, banners) or
`manage_banners` (banners only). Read paths also admit `view_settings`.

Unlike the public surface, these return disabled, scheduled, and expired
records — an operator cannot edit what the panel will not show them.

| Method | Path | Permission |
|---|---|---|
| `GET` | `/admin/homepage/sections` | read |
| `GET` | `/admin/homepage/preview?at=` | read |
| `POST` | `/admin/homepage/sections` | `manage_content` |
| `PATCH` | `/admin/homepage/sections/{id}` | `manage_content` |
| `DELETE` | `/admin/homepage/sections/{id}` | `manage_content` |
| `PATCH` | `/admin/homepage/sections/{id}/status` | `manage_content` |
| `PUT` | `/admin/homepage/sections/reorder` | `manage_content` |
| `GET` | `/admin/banners` | read |
| `POST` | `/admin/banners` | `manage_banners` |
| `PATCH` | `/admin/banners/{id}` | `manage_banners` |
| `DELETE` | `/admin/banners/{id}` | `manage_banners` |
| `PUT` | `/admin/banners/reorder` | `manage_banners` |
| `GET` | `/admin/pages` | read |
| `POST` | `/admin/pages` | `manage_content` |
| `GET` | `/admin/pages/{slug}` | read |
| `PATCH` | `/admin/pages/{slug}` | `manage_content` |
| `DELETE` | `/admin/pages/{slug}` | `manage_content` |
| `PATCH` | `/admin/pages/{slug}/status` | `manage_content` |

### `GET /admin/homepage/sections`

Returns every section plus `meta.available_types` — the section-type catalogue
with each type's label, description, repeatability, and default settings. The
panel's "add section" menu and per-type form controls come from there, so a new
section type is a backend change alone.

### `GET /admin/homepage/preview`

Renders the homepage exactly as the storefront would receive it, optionally at
an arbitrary moment via `?at=<ISO-8601>`. Uncached and resolved live — a preview
served from cache would answer a question nobody asked.

### `PUT /admin/homepage/sections/reorder`

```json
{ "items": [ { "id": 3, "sort_order": 0 }, { "id": 7, "sort_order": 10 } ] }
```

The whole ordering in one transaction. A drop moves every section between the
old and new positions, so sending them individually would leave the page in an
order nobody chose if one call failed.

### Banner and page uploads

`POST /admin/banners` and the two update routes accept multipart. Updates are
sent as `POST` with `_method=PATCH`: PHP does not populate `$_POST` for a
multipart `PATCH` body, so the fields would arrive empty.

The primary image is required on create and optional on update — an edit that
changes only a schedule must not force a re-upload.

### Validation notes

- `link_url` and `cta_url` accept `http(s)://…` or a path beginning `/` only.
  `url` validation alone would admit `javascript:`, which is a stored XSS
  payload the moment it reaches an href.
- `ends_at` must be later than `starts_at`. On a partial update the comparison
  runs against the *merged* state, since a request sending only `ends_at` would
  otherwise satisfy an `after:starts_at` rule vacuously.
- A non-repeatable section type cannot be added twice (`422` on `type`).
- Reserved slugs (`products`, `checkout`, `admin`, …) are refused.
- A system page cannot be deleted (`422`), but is fully editable.
- `settings` is merged over what is stored, not replaced — a scheduling form
  that omitted `settings.items` would otherwise wipe every testimonial.

---

## Frontend webhook

### `POST /api/revalidate`

Served by **Next.js**, not Laravel. Called by the `RevalidateFrontendCache`
job when admin content changes.

**Headers:** `X-Revalidation-Secret: <shared secret>`

```json
{ "tags": ["settings"], "keys": ["general.company_name"] }
```

Known tags: `settings`, `menus`, `catalog`, `content`.

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
