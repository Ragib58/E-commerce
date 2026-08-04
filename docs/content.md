# Dynamic Homepage Builder & CMS

The storefront homepage is not a template. It is an ordered list of rows in
`homepage_sections`, each carrying a type, a payload, an enabled flag, and a
scheduling window. The Next.js page fetches that list and renders whatever it is
given, in the order it is given.

**No homepage business content is hardcoded anywhere in the frontend.** Adding a
section is an `INSERT`. Reordering the page is an `UPDATE`. Scheduling a Black
Friday hero is a date field. None of it is a deploy.

The same principle covers editorial pages: About, Contact, and the four policy
pages are rows in `cms_pages`, resolved by slug at `/p/{slug}`, with their own
SEO metadata and publish state.

---

## 1. Database structure

Three tables, sharing one scheduling contract.

### `homepage_sections`

One row per block of the homepage.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `type` | varchar(40) | `SectionType` — decides the payload shape and the renderer |
| `name` | varchar(255) | Operator-facing label, never shown to shoppers |
| `heading` | varchar(255) null | Shopper-facing heading |
| `subheading` | varchar(512) null | |
| `settings` | json null | Type-specific payload; see §3 |
| `background_color` | varchar(32) null | Hex, validated on write |
| `container_width` | varchar(24) null | `default` \| `narrow` \| `wide` \| `full` |
| `is_enabled` | boolean | |
| `sort_order` | int unsigned | Display order, spaced by 10 |
| `starts_at` / `ends_at` | timestamp null | The scheduling window; null = unbounded |
| `created_at` / `updated_at` / `deleted_at` | timestamp | Soft deletes |

**Indexes**

- `homepage_sections_render_index` on `(is_enabled, sort_order)` — the
  storefront's only query. `is_enabled` leads because it is the selective
  equality predicate, and putting `sort_order` next lets MySQL walk the index in
  order and skip the filesort entirely.
- `homepage_sections_window_index` on `(starts_at, ends_at)`

### `banners`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `title` / `subtitle` | varchar | |
| `image` | varchar | Disk-relative path, never an absolute URL |
| `mobile_image` | varchar null | Art direction for small screens; falls back to `image` |
| `alt_text` | varchar null | Falls back to `title` — an image is never unlabelled |
| `link_url` | varchar(512) null | http(s) or a site-relative path only |
| `link_label` / `link_external` | varchar / boolean | |
| `placement` | varchar(32) | `BannerPlacement` |
| `status` | varchar(16) | `PublishStatus` |
| `sort_order` | int unsigned | Order **within** a placement |
| `starts_at` / `ends_at` | timestamp null | |

**Indexes** — `banners_live_index` on `(placement, status, starts_at, ends_at)`,
`banners_order_index` on `(placement, sort_order)`.

### `cms_pages`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `title` | varchar(255) | |
| `slug` | varchar **unique** | The storefront URL |
| `excerpt` | text null | Derived from the body when not supplied |
| `content` | longtext null | Sanitised HTML — see §5 |
| `featured_image` | varchar null | Disk-relative path |
| `seo_title` / `seo_description` / `seo_keywords` / `og_image` | varchar null | |
| `is_indexable` | boolean | Excludes a page from search without unpublishing it |
| `status` | varchar(16) | `PublishStatus` |
| `is_system` | boolean | Delete guard on the seeded legal pages |
| `starts_at` / `ends_at` / `published_at` | timestamp null | |

### Why one `homepage_sections` table and not eleven

Every section type shares the same lifecycle — enable, order, schedule — and
differs only in payload. Eleven tables would duplicate that lifecycle eleven
times and make "the homepage in display order" a `UNION` across all of them,
which cannot be indexed or paginated sensibly. The differing payload lives in
`settings`, whose shape each `SectionType` case declares.

---

## 2. The scheduling contract

`starts_at` and `ends_at` appear on all three tables and mean the same thing
everywhere. The shared `Schedulable` trait enforces two rules that a hand-rolled
implementation gets wrong:

**The window is evaluated in SQL, not in PHP.** Loading every row and filtering
in a collection would make counts, pagination, and `exists()` disagree with what
is actually visible.

**A null end is open-ended, not expired.** The obvious
`where('ends_at', '>', now())` silently hides every row that was never given an
end date — which is most of them.

Boundaries are inclusive at the start and exclusive at the end, so a section
starting exactly now is live and one ending exactly now is over.

Two conditions must both hold for content to be visible: a publishable status
(or `is_enabled`) **and** an open window. Either alone is a bug — status without
the window shows a campaign before it launches; the window without status shows
a draft.

### Scheduling and caching

A scheduled section starts or expires with no admin action behind it, and
therefore with no revalidation webhook. `HomepageService::resolveTtl()` caps the
cache TTL at the next scheduled transition across all sections and banners, so a
flash sale ending in two minutes is never cached for ten. The frontend's ISR
window is short (60s) for the same reason.

---

## 3. Section types

| Type | Content source | Repeatable |
|---|---|---|
| `hero_slider` | Banners with placement `hero_slider` | no |
| `promo_banner` | Banners with placement `homepage_promo` | yes |
| `featured_products` | Products flagged `is_featured` | no |
| `new_arrivals` | Products flagged `is_new_arrival` | no |
| `best_sellers` | Products flagged `is_best_seller` | no |
| `categories` | Explicit `category_ids`, else top-level categories | no |
| `flash_sale` | Explicit `product_ids`, plus a countdown to `ends_at` | yes |
| `product_collection` | Explicit `product_ids`, else `category_id` | yes |
| `testimonials` | `settings.items`, inline | no |
| `blog_posts` | Later phase — resolves empty, renders nothing | no |
| `custom_content` | `settings.content`, rich text | yes |

`SectionType::catalogue()` is served on `GET /admin/homepage/sections` as
`meta.available_types`. The admin panel's "add section" menu, its per-type form
controls, and its default settings all come from there — so **a new section type
is a backend change alone**.

### Content is resolved at read time, never snapshotted

A featured rail stores `{"limit": 8}`, not eight product ids captured at save
time. Snapshotting would leave an unpublished product advertised on the homepage
until someone re-saved an unrelated section.

Hand-picked collections *do* store ids, and they are resolved in the operator's
chosen order rather than the order `whereIn` happens to return — a product
unpublished since being picked simply drops out.

### Empty sections are dropped

A section whose content resolves to nothing is omitted from the response
entirely. A "Best sellers" heading above a blank strip reads as a broken page,
and the frontend cannot distinguish "not configured" from "nothing matched".
Custom-content blocks are exempt: they *are* their own content.

---

## 4. Frontend rendering

`src/app/page.tsx` contains no section list, no hero markup, and no product
rail. It fetches the ordered array and maps each entry through
`SectionRenderer`, which switches on `type`.

**An unknown section type renders nothing and breaks nothing.** The backend can
ship a new type before the frontend knows how to draw it; the section is skipped
and the rest of the page renders normally. This is why `sectionSchema.type` is a
plain string rather than a Zod enum — a strict enum would fail the whole
homepage on the first unrecognised value.

### Performance

- **One request per page.** The homepage arrives fully resolved; a six-rail page
  would otherwise open with a six-deep waterfall on every cold visit.
- **One priority image.** Only the first section passes `isFirst`, which
  cascades to `priority` on its first row. Marking more defeats the hint.
- **Everything else lazy-loads**, including images inside CMS body HTML — the
  sanitiser adds `loading="lazy"` on write, since those tags never pass through
  `next/image`.
- **`sizes` matches the layout** on every image, so a phone does not download a
  desktop-sized crop.
- **Grid classes are a lookup, not interpolation.** Tailwind scans source
  statically; `md:grid-cols-${n}` is never emitted and the grid collapses.

### Client JavaScript

Only two components ship any: the hero slider (autoplay, keyboard control) and
the flash-sale countdown (it ticks). Every other section is a server component.

The slider stops autoplaying permanently once the visitor takes control, never
starts under `prefers-reduced-motion`, pauses on hover and focus, and keeps
hidden slides `inert` so a keyboard user cannot tab into a slide they cannot
see.

### Dynamic SEO

The homepage takes its title, description, and social image from the settings
API. CMS pages take theirs from the page record, each field falling back
sensibly — an empty tag is worse than a derived one, because search engines
invent their own snippet otherwise.

A page is indexed only if **both** its own `is_indexable` flag and the
store-wide `indexable` setting allow it, so a staging environment is excluded
wholesale regardless of what any page says.

---

## 5. HTML sanitisation

CMS bodies and custom-content sections are authored as HTML and rendered into
every visitor's page. `HtmlSanitiser` reduces them to a strict allowlist
**on write**, so the stored value is the safe value and no read path can bypass
the filter.

That an author is an administrator is not an input filter. A compromised admin
session, a paste from an external document, or a future import script would
otherwise turn one row into stored XSS for every shopper.

Implemented over DOM rather than regular expressions — HTML is not a regular
language, and every regex sanitiser is eventually defeated by nesting, malformed
markup, or entity encoding.

**Removed:** `script`, `style`, `iframe`, `object`, `embed`, `form`, comments,
every `on*` attribute, and any `href`/`src` scheme outside
http/https/mailto/tel. Control characters are stripped before the scheme is
read, so `java\tscript:` does not slip through.

**Added:** `rel="noopener noreferrer"` on `target="_blank"` links, and
`loading="lazy"` on images.

Unlisted tags are *unwrapped*, not deleted — an operator who pasted a `<font>`
around a paragraph meant to keep the paragraph.

The storefront's `RichText` component is the single place
`dangerouslySetInnerHTML` is used, deliberately concentrated so the safety
argument is auditable in one file.

---

## 6. Permissions

| Permission | Grants |
|---|---|
| `manage_content` | Homepage structure, CMS pages, and banners |
| `manage_banners` | Banners only |
| `view_settings` | Read-only access to all three |

Split this way because the roles genuinely differ: a marketing account schedules
campaign imagery constantly, while rewriting the terms and conditions is rarer
and more consequential. A banner manager can place a slide into an existing hero
but cannot add or remove sections.

### System pages

The six seeded pages are marked `is_system`. That flag **only** prevents
deletion — title, slug, body, and status are all editable. A store must be able
to write its own refund policy, and one it cannot edit would be worse than none.
A footer link to a missing privacy policy is a compliance problem, not a
cosmetic one.

They are seeded as **drafts** with visibly unwritten bodies. A plausible-looking
placeholder policy published on day one is a liability; an obvious stub is not.

---

## 7. Caching and invalidation

```
admin saves → ContentChanged → InvalidateContentCache
                                  ├─ Cache::tags(['content'])->flush()
                                  └─ RevalidateFrontendCache job
                                        └─ POST /api/revalidate {tags:['content']}
                                              └─ revalidateTag('content')
```

The whole `content` tag is flushed rather than one key. A banner belongs to a
placement, a placement feeds a section, and a section is part of the cached
homepage payload — evicting only `content:banner:7` would leave the assembled
homepage holding the old slide.

A **catalog** change also flushes the content tag, because the homepage payload
embeds resolved product cards. Only the local cache is flushed there;
`InvalidateCatalogCache` has already dispatched the frontend revalidation for
the same change.

---

## 8. Admin UI

`/admin/homepage` — add sections from the API-supplied catalogue, drag to
reorder, toggle, schedule, edit per-type settings.

Reordering is optimistic: the list moves immediately and reverts if the save
fails. Only the *order* is held locally, never the sections themselves, so the
component never owns a second copy of server state.

Drag-and-drop is built on the native HTML API — the list is short and a drag
library would cost more bundle than the feature. The up/down buttons are not a
fallback but the primary path for keyboard users, since native HTML drag-and-drop
is effectively inoperable from the keyboard.

`/admin/homepage/preview` — renders the storefront's own section components
against the preview endpoint, with a time control. Scheduling that can only be
verified by waiting for the scheduled moment is scheduling nobody trusts.

`/admin/banners` — grouped by placement, because banners are ordered *within* a
placement and a flat list would make the ordering meaningless.

`/admin/pages` — list, edit, publish, with system pages marked and their delete
button absent rather than disabled.

### Timezones

`datetime-local` inputs carry no timezone; the API stores UTC. Passing an ISO
string straight into the input shows the UTC wall-clock time, and submitting it
back shifts the schedule on every save. `lib/dates.ts` converts in both
directions using local getters — never by slicing the ISO string, which is the
usual shortcut and exactly that bug.

---

## 9. API surface

Public, unauthenticated, live records only:

```
GET /api/v1/homepage            The whole page, sections resolved
GET /api/v1/banners?placement=  Live banners, optionally by placement
GET /api/v1/pages               Published pages (titles and slugs only)
GET /api/v1/pages/{slug}        One page, with body and SEO
```

Admin, permission-gated:

```
GET    /api/v1/admin/homepage/sections           + meta.available_types
GET    /api/v1/admin/homepage/preview?at=        Render at any moment
POST   /api/v1/admin/homepage/sections
PATCH  /api/v1/admin/homepage/sections/{id}
DELETE /api/v1/admin/homepage/sections/{id}
PATCH  /api/v1/admin/homepage/sections/{id}/status
PUT    /api/v1/admin/homepage/sections/reorder

GET    /api/v1/admin/banners                     + meta.placements
POST   /api/v1/admin/banners                     multipart
PATCH  /api/v1/admin/banners/{id}                multipart via _method
DELETE /api/v1/admin/banners/{id}
PUT    /api/v1/admin/banners/reorder

GET    /api/v1/admin/pages
POST   /api/v1/admin/pages
GET    /api/v1/admin/pages/{slug}
PATCH  /api/v1/admin/pages/{slug}
DELETE /api/v1/admin/pages/{slug}
PATCH  /api/v1/admin/pages/{slug}/status
```

Banner and page updates accept `POST` with `_method=PATCH`: PHP does not
populate `$_POST` for a multipart `PATCH` body, so the fields would arrive
empty.

The public payload omits `status`, `starts_at`, and `ends_at` — the API only
ever returns live records, so there is nothing for a client to filter, and
giving it those fields would invite it to try. Resolved id lists
(`product_ids`, `category_ids`) are stripped for the same reason: they have
already become `items`.

---

## 10. Configuration

`config/content.php`:

| Key | Default | Purpose |
|---|---|---|
| `cache.enabled` / `cache.ttl` / `cache.tag` | true / 600 / `content` | |
| `homepage.max_sections` | 40 | Ceiling on sections per page |
| `homepage.max_items_per_section` | 48 | Ceiling after a section's own `limit` |
| `html.allowed_tags` / `allowed_attributes` / `allowed_schemes` | see file | The sanitiser allowlist |
| `html.max_length` | 200000 | |
| `system_pages` | six slugs | Seeded and protected from deletion |
