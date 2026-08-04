# Customer Storefront

The shopping surface: browsing, search, faceted filtering, the cart, wishlist,
comparison, and the account area.

The load-bearing decision in this phase is a negative one. **No price ever
reaches the database from a request, and no price is ever read back from a cart
row.** Everything else here follows from that.

---

## 1. Why `cart_items` has no price column

A client may say *what* it wants and *how many*. Unit price, discount, line
total, subtotal, tax, and availability are all derived by `CartService` from
`products` and `product_variants` at the moment of reading.

That is stricter than validating a submitted price, deliberately. Validation
compares a client's number against the catalog and rejects a mismatch — which
means the comparison is a code path that can be skipped, mis-ordered, or
forgotten on the next endpoint someone adds. Deriving the number means there is
no client-supplied price anywhere in the system to check: the class of bug is
absent rather than defended against.

The consequences are visible in the schema and the request classes:

- `cart_items` has `cart_id`, `product_id`, `product_variant_id`, `quantity`,
  `options`. No `price`, no `subtotal`, no `discount`.
- `AddCartItemRequest` defines four rules — product, variant, quantity,
  options. There is no rule *rejecting* a price because there is no field to
  submit one into; `validated()` discards everything else before it reaches the
  service.
- `CartItem` has no accessor that returns money.

### The trade-off, stated

Prices can change under a shopper between page loads. `CartService::summarise()`
surfaces that as a per-line `issues` entry rather than letting it pass
unnoticed, and unsellable lines are excluded from the total rather than silently
dropped from the cart.

Price-at-add-time becomes meaningful at *order* placement, where it is captured
onto the order. **An order is a contract; a cart is an intention.**

### What is recalculated

| Figure | Source |
|---|---|
| Unit price | `variant.effective_price` when a variant was chosen, else `product.effective_price` — both already resolve the discount-vs-list and variant-inherits-from-parent questions |
| List price | `variant.base_price` / `product.price`, emitted only when genuinely higher |
| Line total | `unit_price × quantity`, server-side |
| Discount | `(list − unit) × quantity` |
| Tax | The taxable subtotal × `business.tax_rate` from store settings, rounded **once** at the end |
| Availability | `variant.stock` / `product.effective_stock`, honouring `allow_backorder` and untracked types |
| Shipping | **Not computed.** It depends on an address the cart does not have; a placeholder that changes at checkout reads as a hidden cost |

Tax is summed then rounded, not rounded per line: per-line rounding accumulates
a fraction of a penny per item, so a ten-line cart disagrees with the same order
totalled elsewhere — and the discrepancy is invisible until an accountant finds
it.

---

## 2. Guest and authenticated carts

One `carts` table serves both, keyed by *either* `user_id` or `token`. The
alternative — a session cart for guests and a database cart for members — means
two storage engines, two pricing paths, and a merge that reconciles shapes that
were never the same. Here the merge is an `UPDATE` plus a line reconciliation,
and every other code path is identical.

### The guest credential

A 64-character token from `random_bytes(32)`. It is a bearer credential for a
basket, so it is generated with a CSPRNG — a short or sequential id would let
anyone enumerate and empty strangers' carts.

**It travels in an `X-Cart-Token` header, not a cookie the API sets.** The brief
asks for a cookie cart, and that does not fit this architecture:

- The `api` middleware group is stateless by design; Sanctum's cookie
  middleware is not registered, precisely so the API carries no ambient
  credential and has no CSRF surface. A cookie the browser attaches
  automatically reintroduces it — an attacker's page could POST to
  `/cart/items` and have the victim's cart identity included for free.
- The storefront and API are separate origins, so a cross-site cookie needs
  `SameSite=None; Secure`, which browsers increasingly partition or block.

The token is still *stored* in a cookie — a first-party one written by the
Next.js app on its own origin, which the browser keeps and the Next server can
read for SSR. Nothing is sent automatically, so a cross-site request carries no
cart identity at all.

`X-Cart-Token` must appear in the API's CORS `exposed_headers`, or the browser
hides the minted token and every guest request creates a fresh empty cart.

### Resolution rules

| Request | Resolves to |
|---|---|
| Authenticated | That user's cart, **always** — a supplied token is ignored |
| Guest with a valid token | The guest cart with that token, and only if `user_id` is null |
| Guest, no token | A new cart, but **only on an unsafe method** |

Two properties fall out of this. A leaked cookie cannot reach a signed-in
shopper's basket, because a user's cart holds no token. And a crawler issuing
`GET /cart` does not insert a row per request.

### Merging on sign-in

Called once after login and registration. Quantities for the same
`(product, variant)` are summed and re-clamped to what is available; the guest
row is deleted; the token is cleared. Idempotent, so a client calling it on
every page load cannot double a quantity.

---

## 3. Stock

Availability is checked on every mutation, but **a cart does not reserve
stock**. Two shoppers can both hold the last unit; the one who checks out first
gets it.

Reserving at add-to-cart would let anyone deny the catalog to everyone else by
filling a basket. The authoritative decrement happens once, inside
`InventoryService`, under a row lock, at order placement.

Adds are checked against the *resulting* quantity, not the delta: adding 1 to a
line already holding the last 3 must fail, even though 1 alone is available.

### Concurrency

- Both `add` and `updateQuantity` take a row lock on the cart before reading a
  line's quantity, so two racing clicks cannot both read 1 and both write 2.
- A unique index on `(cart_id, product_id, variant_key)` stops duplicate lines.
  `variant_key` exists because every SQL engine treats NULLs as distinct in a
  unique index, which would exempt simple products entirely; it is maintained
  by a model hook rather than a generated column so the schema is identical on
  MySQL and the SQLite the tests run on.
- A unique violation on insert is caught and converted into an increment — from
  the shopper's side that is an ordinary double click, not an error.

---

## 4. Wishlist, compare, recently viewed

Three features, three different storage decisions, each for a reason.

| Feature | Guest | Signed in | Why |
|---|---|---|---|
| Wishlist | localStorage | `wishlist_items` table | Its value is outliving the session and following the shopper between devices — which needs an account. A guest has no identity to hang one on. |
| Compare | localStorage | localStorage | Comparison is a within-session act: line up three kettles, pick one, never want the list again. A table and a merge path for data with a useful life of minutes is not worth it. |
| Recently viewed | localStorage | localStorage | Per-device browsing history. Storing it server-side turns an incidental convenience into a tracked behavioural record with the retention and privacy obligations that carries. |

All three store **identifiers only, never product data**. A cached product goes
stale the moment its price changes, and a wishlist showing last month's price is
worse than one that fetches fresh. Compare and recently-viewed resolve their
lists through `POST /catalog/products/lookup`, which returns products in the
order asked for — `whereIn` returns index order, which would scramble a rail
whose entire meaning is its ordering.

`useWishlist` is the seam that hides the guest/server split: components ask "is
this saved?" and "toggle this" and never learn which. Without it every product
card would carry an `isAuthenticated ? … : …` branch, and the branches would
drift.

---

## 5. Server and client components

The division is deliberate and worth stating, because it is the difference
between a page that ships 20 KB of JavaScript and one that ships 200 KB.

**Server components** — every page shell, the product grid, the product card's
markup, breadcrumbs, headings, prices, badges. A shopper and a crawler both
receive products in the HTML.

**Client components** — only what reacts to input:

| Component | Why it needs the client |
|---|---|
| `ProductFilter` | Reads and rewrites the URL |
| `CatalogSort` | Same |
| `AddToCartButton` | Mutation with pending and error state |
| `WishlistToggle` / `CompareToggle` | Reads localStorage or a query cache |
| `CartDrawer` / `CartItem` / `CartView` | Cart mutations |
| `SiteHeader` | Session, badges, search box |
| `RecentlyViewedRail` | localStorage |

`ProductCard` stays a server component and imports the interactive corners.
Making the whole card a client component to get a working wishlist button would
ship every product's markup as JavaScript, on a page that renders twenty-four of
them.

### The card's link structure

The title carries the only anchor, stretched across the card with an `::after`
overlay. Wrapping the whole card in a `<Link>` would nest the action buttons
inside an anchor — invalid HTML, and unreachable for assistive technology
regardless of how many `preventDefault` calls are added.

---

## 6. State management

| State | Where | Why |
|---|---|---|
| Cart contents | TanStack Query | The server owns them |
| Cart drawer open | Zustand | The server does not know about it |
| Wishlist (guest) | Zustand + localStorage | Client-owned, must persist |
| Compare, recently viewed | Zustand + localStorage | Same |
| Filters, sort, page | The URL | Shareable, refresh-proof, back-button-correct |
| Session | Zustand + sessionStorage | Existing auth phase |

**The rule: Zustand holds what the server does not know about.** Putting cart
contents there too would create two copies that drift, and the one the UI reads
would be the wrong one.

Cart mutations write the server's response into the cache with `setQueryData`
rather than invalidating and refetching — the API already returns the whole
recomputed cart, and the window between a mutation resolving and a refetch
landing is exactly when a shopper sees a stale total.

**Nothing is optimistic.** An optimistic cart has to guess the new subtotal,
which means computing prices on the client — the one thing this phase exists to
avoid.

### Hydration

Every store exposes `isHydrated`, and components must not render an active
state before it. The server rendered the empty state, so drawing a filled heart
on the first client pass is a React hydration mismatch as well as a visible
flicker on every card in a grid.

---

## 7. Pages

| Route | Rendering | Indexed |
|---|---|---|
| `/` | ISR, dynamic sections | yes |
| `/products` | Server, streamed grid | only unfiltered |
| `/categories/[slug]` | Server, streamed grid | only unfiltered |
| `/search?q=` | Server, streamed grid | **never** |
| `/products/[slug]` | Server, per-request | yes |
| `/cart`, `/wishlist`, `/compare`, `/account/*` | Client view, server shell | never |

Filtered listings are not indexed because every facet combination is a distinct
URL; indexing them puts thousands of near-identical thin pages into search
results and dilutes the canonical listing. `follow` stays on, so linked products
are still discovered.

Grids stream inside a `Suspense` boundary **keyed on the filter state**. Without
the key React keeps the previous subtree mounted and the page appears frozen
while the server works.

### The four states

Every data-backed page handles loading, error, empty, and populated explicitly.
The middle two are not afterthoughts: an empty basket rendered as blank space
reads as a failure to load, and a failed fetch rendered as an empty basket tells
a shopper their items are gone when they are not.

Skeletons are shaped like the real content so nothing reflows when data arrives.

---

## 8. Images

- `next/image` throughout, with `sizes` matching each layout — a half-width
  banner told it occupies `100vw` fetches an image twice the size it will show.
- `priority` on the first row only. Marking more makes them compete and defeats
  the hint.
- Everything else lazy-loads.
- Grid column classes are a lookup, never `lg:grid-cols-${n}`: Tailwind scans
  source statically, so an interpolated class is never emitted.

---

## 9. API surface

```
GET    /api/v1/cart                    priced, guest or authenticated
DELETE /api/v1/cart                    empty it
POST   /api/v1/cart/items              add        { product, variant?, quantity?, options? }
PATCH  /api/v1/cart/items/{item}       set quantity (0 removes)
DELETE /api/v1/cart/items/{item}
POST   /api/v1/cart/coupon             stored, not applied — see below
POST   /api/v1/cart/merge              claim a guest cart (authenticated)

GET    /api/v1/wishlist                authenticated
POST   /api/v1/wishlist                { product }
DELETE /api/v1/wishlist/{product}
POST   /api/v1/wishlist/merge          { products: [...] }

POST   /api/v1/catalog/products/lookup { products: [...] }  compare + recently viewed
```

Every cart endpoint returns the **whole recomputed cart**, not the line that
changed. A quantity change moves the subtotal, the tax, and possibly another
line's availability, so returning one item would force the client to refetch or
recompute — and a client that computes totals is a client whose totals can be
wrong.

Cart routes carry no `auth` middleware. The cart *is* the authorization
boundary: a request can only act on the cart its bearer token or header resolves
to. Adding `auth:sanctum` would make them 401 for guests, and the point is that
the same endpoints serve both.

### The coupon placeholder

`POST /cart/coupon` stores the code and reports `applied: false` with a reason.
It is not wired to a discount because promotions are a later phase.

Storing it matters: a shopper who arrives holding a code should not lose it.
Reporting a zero discount as "applied" would render as a broken promotion rather
than an unbuilt feature.

---

## 10. Housekeeping

`carts:prune` deletes guest carts untouched for 30 days, scheduled at 03:20.
Every anonymous visitor who adds an item creates a row whose only key lives in
one browser's cookie; once that is gone the row is unreachable forever.

**Signed-in customers' carts are never pruned** — enforced by the model's
`abandonedGuest` scope rather than a condition in the command, so no caller can
widen it by accident. Deletion is chunked: a single `DELETE` over months of rows
holds locks that cascade into `cart_items`, stalling live shoppers.

---

## 11. Rate limiting

Cart mutations have their own budget (`API_RATE_LIMIT_CART`, default 60/min)
rather than sharing the public read limit. They are writes available to
unauthenticated visitors — the cheapest endpoint from which to create rows — and
a shopper clicking "+" repeatedly must not exhaust the budget the rest of their
browsing depends on.

Keyed on the user when authenticated, else the cart token, else the IP. The
token key matters: several guests behind one NAT would otherwise share one
allowance.
