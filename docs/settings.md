# Dynamic Store Settings, Branding & Theme

Every value the storefront renders as branding — company name, logos, favicon,
colours, contact details, social links, SEO metadata, analytics tags, currency
and tax rules — is stored in the database and editable at runtime from the admin
panel.

Nothing in this list is hardcoded in the Next.js app, and nothing in it lives in
an environment variable. Environment variables hold secrets and infrastructure
addresses; **business branding is data**.

Adding a new brandable field is an `INSERT`, not a migration and a deploy.

---

## 1. Database structure

### `settings`

An entity-attribute-value table. One row per configurable value.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `key` | varchar(191) **unique** | Dot-namespaced: `theme.primary_color` |
| `value` | text **null** | Nullable because "unset" differs from `""` |
| `type` | varchar(32) | `SettingType` — drives cast + validation |
| `group` | varchar(32) | `SettingGroup` — drives admin tabs + exposure |
| `label` | varchar(255) null | Admin-facing field label |
| `description` | varchar(500) null | Admin-facing help text |
| `is_public` | boolean | Gate 1 for storefront exposure |
| `is_locked` | boolean | Seeded infrastructure key; cannot be deleted |
| `sort_order` | int unsigned | Field order within a group |
| `created_at` / `updated_at` | timestamp | |

**Indexes**

- `settings_key_unique` on `key`
- `settings_public_group_sort_index` on `(is_public, group, sort_order)` —
  covers the public endpoint's access path entirely
- `settings_group_index` on `group`

`type` and `group` are stored as strings validated by PHP enums rather than
native DB enums: adding a case must not require an `ALTER TABLE`, and the
constraint is then enforced identically on MySQL and PostgreSQL.

### Why EAV rather than a wide `store_settings` table

A wide table makes every new brandable field a migration plus a deploy, and the
admin panel needs a hardcoded field map to render it. With EAV, the settings
table *is* the form definition: `type` selects the input control and its
validation rule, `label`/`description` supply the copy, `group` places it in a
tab. A setting inserted at runtime appears in the panel and on the API with no
code change — covered by a test.

The trade-off is no per-column DB typing. That is bought back by `SettingType`,
which casts on read and validates on write, so `feature.reviews_enabled` comes
back as a real `bool` and not `"1"`.

### `SettingType`

`string` · `text` · `integer` · `float` · `boolean` · `json` · `color` ·
`image` · `file` · `url` · `email`

`image`/`file` store a **disk-relative path** and are expanded to absolute URLs
on read. Storing a URL would mean rewriting every row to change a CDN domain.

### `SettingGroup`

| Group | Public | Contents |
|---|:---:|---|
| `general` | ✅ | Company name, tagline, description, locale, maintenance mode |
| `branding` | ✅ | Logo, light logo, dark logo, favicon, OG image, brand description |
| `theme` | ✅ | Primary/secondary/accent/background/text/button/destructive colours, radius, font |
| `contact` | ✅ | Email, phone, address, Google Maps URL, support hours |
| `social` | ✅ | Facebook, Instagram, X, LinkedIn, YouTube, TikTok |
| `seo` | ✅ | Website title, meta title/description/keywords, indexable |
| `analytics` | ✅ | Google Analytics ID, Facebook Pixel ID |
| `business` | ✅ | Currency, symbol, tax rate, VAT rate, order prefix, invoice prefix |
| `feature` | ✅ | Wishlist, reviews, guest checkout toggles |
| `mail` | ❌ | Sender identity |
| `payment` | ❌ | Gateway configuration |
| `shipping` | ❌ | Shipping rules |

**Two gates for public exposure.** A setting reaches the storefront only if
`is_public = true` **and** its group is publicly exposable. A mistaken
`is_public` toggle on a payment credential therefore still cannot leak it —
there is a test asserting exactly this.

---

## 2. API endpoints

### Public

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/v1/settings/public` | Storefront configuration, grouped |
| `GET` | `/api/v1/settings/public?group=theme` | One group only |

### Admin (`auth:admin-api` + active + current password + permission)

| Method | Path | Permission |
|---|---|---|
| `GET` | `/api/v1/admin/settings` | `view_settings` or `manage_settings` |
| `GET` | `/api/v1/admin/settings/groups` | `view_settings` or `manage_settings` |
| `PUT` | `/api/v1/admin/settings` | `manage_settings` |
| `POST` | `/api/v1/admin/settings/media/{key}` | `manage_settings` |
| `DELETE` | `/api/v1/admin/settings/media/{key}` | `manage_settings` |
| `POST` | `/api/v1/admin/settings/cache/flush` | `manage_settings` |

### Blade admin panel

| Method | Path |
|---|---|
| `GET` | `/admin/settings?group=theme` |
| `PUT` | `/admin/settings/{group}` |
| `POST` | `/admin/settings/media/{key}` |
| `DELETE` | `/admin/settings/media/{key}` |
| `POST` | `/admin/settings/cache/flush` |

Full request/response shapes: [api.md](api.md).

---

## 3. Service layer

`SettingsService` is the only writer. The public API, the admin API, and the
Blade panel all call it, so every mutation takes one code path — one
transaction, one cache flush, one `SettingsUpdated` event. Nothing queries the
`Setting` model directly.

```
all()                  every setting, keyed and typed        (cached)
publicGrouped()        storefront payload, grouped           (cached)
get(key, default)      one value, typed
group(g)               one group
allForAdmin(?group)    full rows incl. private groups        (uncached)
rawValue(key)          stored value, bypassing the cast
validationRulesFor()   rules derived from declared types
set(key, …)            single write + flush
setMany([...])         bulk write, one transaction, one flush
setMedia(key, file)    upload + replace + flush
clearMedia(key)        delete file + null the value + flush
forget(key)            delete (refuses locked settings)
flush()                invalidate + dispatch SettingsUpdated
version()              monotonic stamp for cache keying
```

`setMany` exists because a per-key loop over `set()` would flush the cache N
times and emit N events for one admin save.

`MediaService` handles the filesystem, writing through Laravel's `Storage`
facade so the same code serves the local `public` disk in development and
S3/MinIO in production. Switching `FILESYSTEM_DISK` moves asset delivery with no
code or data change — which is why nothing builds a URL by concatenation.

Uploads get a regenerated filename (`company-logo-a1b2c3d4e5f6.svg`): the client
controls the original name, and preserving it invites both collisions and path
traversal. The original survives as a slug for recognisability.

---

## 4. Admin UI

`/admin/settings` renders one group at a time. Tabs come from `SettingGroup`, so
a new group appears with no view change; fields come from the settings rows, so
a new setting appears with no view change either.

The control is chosen from `type`:

| Type | Control |
|---|---|
| `color` | Native colour swatch bound to a hex text input |
| `boolean` | Checkbox with a hidden `0` companion |
| `text` / `json` | Textarea |
| `integer` / `float` | Number input |
| `email` / `url` | Typed input |
| `image` / `file` | Upload card with live preview |

Two details worth knowing:

- **The hidden `0` companion.** An unchecked checkbox submits nothing. Without
  the companion field, "off" is indistinguishable from "not in this form" and a
  toggle could never be turned off.
- **Media posts separately.** Uploads need `multipart`, and uploading one asset
  must not resubmit or clobber the others — so each asset card is its own form.

Colour swatches are progressive enhancement: the hex text input is authoritative
and fully usable with JavaScript disabled. The server never derives a colour
from the picker.

Asset previews sit on a chequerboard so a transparent PNG reads as transparent,
and the dark-logo preview sits on a dark plate so a white logo is visible.

---

## 5. Frontend integration

### StoreConfig

`buildStoreConfig()` turns the raw API payload into one typed facade. Components
read branding from it rather than reaching into the settings payload, so derived
rules live in one place:

- an unset `button_color` falls back to the primary colour;
- `logo_light` / `logo_dark` each fall back to the primary logo, so uploading
  one asset still gives a logo everywhere;
- `website_title` falls back to the company name;
- social links with no URL are dropped rather than rendered dead;
- `formatPrice()` uses `Intl` with the configured currency and locale, falling
  back to the raw symbol when the code is not one `Intl` recognises — an
  operator can type anything into that field, and a `RangeError` must not take
  down a product listing.

Server components call `getStoreConfig()`, wrapped in React `cache()` so the
root layout's three consumers (`generateMetadata`, `generateViewport`, the body)
share one fetch per request. Client components read `useStoreConfig()`, fed by
`StoreConfigProvider` in the root layout.

### CSS variables

`buildThemeCss()` converts admin colours into custom properties injected into a
`<style>` block during **server** rendering, so correct colours are in the
initial HTML. Applying them on the client would flash the default theme first.

```
--primary-color     --secondary-color   --accent-color
--background-color  --text-color        --button-color
--primary           --secondary         --accent        (HSL triples)
--background        --foreground        --button
--primary-foreground  --button-foreground  …            (auto-contrast)
--radius            --font-family-brand
```

Colours are emitted **twice**: as bare HSL triples (`221 83% 53%`) that Tailwind
composes with alpha — `bg-primary/50` expands to `hsl(var(--primary) / 0.5)` —
and as the literal `--*-color` hex names for plain CSS.

Paired `*-foreground` values are computed with the WCAG relative-luminance
formula, so an admin choosing a pale brand colour gets dark text on buttons
rather than unreadable white-on-pale-yellow.

**Injection safety.** Colours are re-serialised from parsed integers, never
echoed from admin input. `radius` and `font_family` are free text, so each is
matched against a strict allowlist (`^\d{1,3}(\.\d{1,3})?(px|rem|em|%)$` and
`^[A-Za-z0-9 -]{1,64}$`); a value containing `}` would otherwise close the rule
and inject arbitrary CSS.

### Dynamic metadata, favicon, logo

`generateMetadata` builds title, description, keywords, robots, icons, OpenGraph
and Twitter cards from settings. The favicon is declared here rather than
shipped as a static `app/icon` file, because it is an admin upload.

`<BrandLogo>` emits both logo variants and toggles them with
`prefers-color-scheme` in CSS — a JavaScript choice would render the wrong logo
on the server and swap it after hydration, a visible flicker on every load. With
no logo uploaded it falls back to the company name as a wordmark, never to a
placeholder image.

Logos use a plain `<img>`, not `next/image`: the source is an arbitrary
admin-supplied URL that may live on S3, MinIO, or a CDN added later, and
`next/image` would require every host in `next.config` — turning an admin upload
into a deploy.

### Analytics

`<AnalyticsScripts>` injects GA and Facebook Pixel tags only when an ID is
configured. Both IDs are interpolated into inline script bodies, so each is
validated against a strict pattern first (`^(G|UA|AW|GT)-[A-Z0-9-]{4,20}$`,
`^\d{6,20}$`); an unvalidated value reaching the script would be an injection
vector. Loaded `afterInteractive`, so measurement never sits on the critical
path to first paint.

Nothing renders when no ID is set. Loading a tracker the operator did not
configure is a privacy problem, not a missing feature.

### Degradation

If the API is unreachable the frontend renders neutral fallbacks and a visible
warning — never a stale hardcoded brand. `FALLBACK_SETTINGS` is deliberately
generic (`"Store"`): showing a wrong company name would be worse than showing
none. The production build succeeds with the API down, which is the same path.

---

## 6. Cache strategy

Three layers, invalidated in one direction.

```
admin save
    │
    ├─ SettingsService::setMany()      one transaction
    ├─ cache tag "settings" flushed    Redis, DB 1
    └─ SettingsUpdated event
           ├─ bumpVersion()            meta.version increments
           └─ RevalidateFrontendCache  queued job
                  └─ POST /api/revalidate → Next.js purges tag "settings"
```

| Layer | Store | Key / tag | TTL | Invalidated by |
|---|---|---|---|---|
| Laravel settings payload | Redis DB 1 | tag `settings`, keys `settings:public`, `settings:all` | `CACHE_TTL_SETTINGS` (24 h) | Tag flush on write |
| Version stamp | Redis, untagged | `settings:version` | forever | Incremented on write |
| Next.js data cache | Next.js | tag `settings` | `REVALIDATE_SECONDS.settings` | Webhook; TTL is only a backstop |

**Why one cache key, not one per setting.** The storefront always requests the
whole branding payload. A single key means one Redis round-trip per request
instead of thirty, and invalidation is one tag flush.

**Why the version stamp is untagged.** It must survive the tag flush that
accompanies every write — tagged, it would reset to `1` on each change.

**`CACHE_STORE` must be `redis`.** Cache tags silently no-op on the `file`
driver, which would serve stale branding after an admin change. `SettingsService`
detects a driver without tag support and falls back to the untagged store so
reads still work; `flush()` then clears globally.

**Redis uses three logical databases** so `cache:clear` cannot destroy live
sessions or drop queued jobs.

The webhook is the primary invalidation path; the ISR window is a backstop for a
missed call. Both exist because either alone has a failure mode: a webhook can
be lost, and a TTL alone would leave stale branding live for its full duration.

---

## 7. Testing checklist

### Automated

```bash
docker compose exec php php artisan test          # 179 passing, in-memory SQLite
docker compose exec php php artisan test --filter=SettingsManagementTest   # 19
docker compose exec php php artisan test --filter=SettingsPanelTest        # 15
docker compose exec php php artisan test --filter=MediaServiceTest         # 12
docker compose exec php php artisan test --filter=PublicSettingsTest       #  8

cd frontend && npm run typecheck && npm run lint && npm run build
```

Covered by tests:

- [x] Public payload is grouped, prefix-stripped, and type-cast
- [x] Private groups (`mail`, `payment`) never appear publicly
- [x] `is_public` alone cannot leak a payment credential (two-gate rule)
- [x] `?group=` narrows; a non-public group returns `422`
- [x] A write invalidates the cache and bumps `meta.version`
- [x] Unauthenticated admin requests → `401`
- [x] An admin without `manage_settings` → `403`
- [x] `view_settings` can read but not write
- [x] Admin read exposes private groups and form metadata
- [x] Bulk update reaches the public endpoint immediately
- [x] Declared types survive a write (`false` stays `false`, floats stay floats)
- [x] An invalid colour rejects the **whole** submission — no partial write
- [x] Unknown keys are rejected, not created
- [x] Logo upload stores a relative path, serves an absolute URL
- [x] All four asset slots upload independently
- [x] Replacing an asset deletes the previous file
- [x] Removing an asset deletes the file and nulls the value, keeping the row
- [x] Non-image uploads rejected; uploads to non-file settings rejected
- [x] Filenames are regenerated; traversal names neutralised; no collisions
- [x] Unknown upload directory refused
- [x] A setting inserted at runtime appears with no migration

Admin panel (Blade views actually rendered, not just the API):

- [x] The settings page renders; every group renders its own fields
- [x] Colour fields emit a swatch bound to their hex input
- [x] Boolean fields emit the hidden `0` companion
- [x] Media settings render upload cards *outside* the bulk form
- [x] An unknown group falls back to General; saving one 404s
- [x] Saving a group persists; an invalid colour returns a field error
- [x] An unchecked toggle saves as `false` (the companion works)
- [x] An empty value stores as `NULL` so the frontend fallback applies
- [x] A key from another group is not written by this group's form
- [x] Upload and removal work; uploads to non-media settings 404
- [x] Cache flush from the panel bumps the version

### Manual

Branding

- [ ] Change the company name → header, footer, page `<title>`, and OG tags update
- [ ] Upload a logo → header shows it; remove it → header falls back to the wordmark
- [ ] Upload only a light logo → it shows in both colour schemes
- [ ] Upload both variants → the correct one shows per OS colour scheme
- [ ] Upload a favicon → browser tab icon changes (hard-reload; browsers cache these hard)
- [ ] Upload a transparent PNG → admin preview shows the chequerboard

Theme

- [ ] Change the primary colour → buttons and links restyle with no rebuild
- [ ] Set a pale primary colour → button text turns dark and stays readable
- [ ] Set `button_color` → buttons change independently of links
- [ ] Clear `button_color` → buttons return to the primary colour
- [ ] Change the border radius → corners change
- [ ] Set an invalid colour (`red`, `javascript:alert(1)`) → rejected with a field error
- [ ] Set `font_family` to `Inter}` → refused by the allowlist, no CSS injection

Website / SEO

- [ ] Change the website title → tab title and the `%s — title` template update
- [ ] Change the meta description → `<meta name="description">` updates
- [ ] Toggle `indexable` off → `robots` becomes `noindex, nofollow`

Analytics

- [ ] Set a GA ID → `gtag.js` loads; clear it → no analytics request is made
- [ ] Set a Pixel ID → `fbevents.js` loads with the `<noscript>` fallback
- [ ] Set a malformed ID (`G-<script>`) → no script is injected

Contact / social / business

- [ ] Add a phone → footer shows a `tel:` link; clear it → the row disappears
- [ ] Add a Google Maps URL → "View on map" appears with `rel="noopener noreferrer"`
- [ ] Add TikTok → it appears in the footer in the configured order
- [ ] Clear every social URL → the whole "Follow" block disappears
- [ ] Change the currency to `EUR` → prices render as `€1,234.50`
- [ ] Set a nonsense currency code → falls back to the symbol, no crash
- [ ] Change the tax rate → the value reaches the storefront as a real float

Cache & resilience

- [ ] Save any setting → the storefront reflects it without a manual purge
- [ ] "Clear cache" → `meta.version` increments
- [ ] Stop the API → the storefront renders neutral fallbacks plus a warning,
      never a stale brand
- [ ] Restart the API → branding returns without a rebuild
- [ ] Switch `FILESYSTEM_DISK` to `s3` → existing assets still resolve

Permissions

- [ ] Sign in as a Support Agent → settings are unreachable
- [ ] Grant `view_settings` only → the form loads, saving is refused
