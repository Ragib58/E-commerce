# Production Readiness

A full audit of the platform's security, performance, and test coverage, plus
the fixes applied and what must be configured before a real deployment.

Run `php artisan app:production-check` on the target environment before every
deploy. It exits non-zero on anything unsafe, so a pipeline can gate on it.

---

## 1. Critical issues found and fixed

Six issues were found. Two were live vulnerabilities; four were latent bugs
that had been failing in the test suite for several phases.

### 1.1 Stored XSS via SVG upload — **fixed**

**Severity: high.** Admin-uploaded assets are served from `/storage` on the
*same origin* as the application. SVG is on the upload allowlist (logos are
commonly SVG), and an SVG can carry `<script>`. Because the file genuinely
*is* `image/svg+xml`, `X-Content-Type-Options: nosniff` does not help, and no
CSP was set on that path.

Navigating to such an upload executed attacker script with full same-origin
access to session cookies and the admin API. Confirmed exploitable by serving
a probe SVG and observing `Content-Type: image/svg+xml` with no restricting
headers.

**Fix** (`docker/nginx/conf.d/default.conf`): the `/storage` location now sends
a sandbox CSP, and SVG/SVGZ additionally get `Content-Disposition: attachment`.
The sandbox gives the document an opaque origin, so any script it contains can
reach neither cookies nor the API. Ordinary `<img>` rendering never executes
script and is unaffected — verified that PNG still serves inline and SVG still
returns 200 for legitimate `<img>` use.

### 1.2 No Content-Security-Policy on the storefront — **fixed**

**Severity: medium.** The nginx config deferred CSP to the Next.js layer, on
the sound reasoning that only the app knows its own script origins — but the
Next.js layer never defined one, so the site shipped with no CSP at all.

**Fix** (`frontend/next.config.ts`): a full policy including `object-src
'none'`, `base-uri 'self'`, `form-action 'self'`, and `frame-ancestors 'none'`.
`'unsafe-eval'` and `ws:` are included only in development (React Refresh and
HMR), so the weaker policy never reaches production. The stale nginx comment
now points at where the policy actually lives.

### 1.3 Protocol-relative URLs bypassed the XSS sanitiser — **fixed**

**Severity: medium.** `HtmlSanitiser::isSafeUrl()` had an ordering bug: the
root-relative check (`str_starts_with($url, '/')`) ran *before* the
protocol-relative check (`//`). Since `//evil.test` also starts with `/`, it
returned early and the protocol-relative branch was unreachable dead code.

A link to `//evil.test/phish` in CMS content therefore survived sanitisation
and rendered as a live off-origin link — an open-redirect and phishing vector
in operator-authored content.

**Fix**: the two checks are reordered, with a comment explaining that the order
*is* the check.

### 1.4 Null bytes defeated URL scheme validation — **fixed**

**Severity: low-medium.** `href="java\0script:alert(1)"` was truncated by the
DOM parser to `href="java"` before the sanitiser could judge it, and the
element's text was discarded along with it. The output was inert, but the
payload never reached the scheme allowlist — the safety came from a parser
accident rather than from the check.

**Fix**: C0 control characters are stripped before parsing, so the string the
sanitiser inspects is the string a browser would act on. Tab, newline, and
carriage return are preserved as legitimate whitespace.

### 1.5 Global route binding broke wishlist deletion — **fixed**

**Severity: medium (functional).** `Route::bind('product', …)` in
`RouteServiceProvider` is global — it fires for any `{product}` segment on any
route, despite a docblock claiming it was scoped to admin routes.

`DELETE /wishlist/{product}` types its parameter as `string` and resolves the
identifier itself so it can answer with a friendly 422. The binder ran first,
failed its slug lookup on a uuid, and turned the route into a 404. **Removing a
saved wishlist item was broken for every customer.**

**Fix**: the binder now inspects the controller's actual type hint via
reflection. A route type-hinting the model gets a resolved model; one
type-hinting a string gets the raw string. Anything unresolvable falls back to
the previous behaviour, so no working route changes. Verified across 51
catalog and admin binding tests plus the 14 wishlist tests.

### 1.6 Page index returned 500 — **fixed**

**Severity: medium (functional).** `CmsPageResource` read `created_at`
unconditionally, but the footer index deliberately selects a narrow column list
(it must not ship six full policy documents to render six links). Under
`Model::shouldBeStrict()` that threw `MissingAttributeException`, so
`GET /api/v1/pages` was a 500 — the footer navigation endpoint.

**Fix**: `created_at` now uses the same `$loaded()` guard every other optional
column in that resource already used.

---

## 2. Security audit

| Area | Status | Notes |
|---|---|---|
| Authentication | ✅ | Sanctum tokens, 7-day expiry, hashed passwords, separate guards per principal |
| Authorization | ✅ | Permission middleware + policies; reporting exports gated separately from reads |
| Rate limiting | ✅ | 8 named limiters — auth keyed on email+IP, cart on token, checkout on session |
| Input validation | ✅ | Form requests throughout; filters bounded against enums |
| File uploads | ✅ | MIME allowlist (real type, not extension), size cap, directory allowlist, sandboxed serving |
| XSS | ✅ | HTML allowlist sanitiser + CSP; two bypasses fixed above |
| SQL injection | ✅ | No interpolated user input in raw SQL; sort columns allowlisted via config |
| API security | ✅ | Stateless bearer tokens, no ambient credential, versioned envelope |
| Webhook verification | ✅ | HMAC with `hash_equals`, replay window; unconfigured gateways fail closed |
| CSRF | ✅ | Enabled for the Blade panel, excluded only for stateless `api/*` |
| CORS | ✅ | Explicit origin allowlist, no wildcard |

### Client trust boundary

The brief asks specifically that the frontend cannot manipulate prices,
discounts, stock, or payment status. Each is verified by a test that *attempts
the manipulation* and asserts it failed — see
`tests/Feature/ProductionReadiness/ClientTrustBoundaryTest.php`.

The two strongest guarantees are structural rather than validated:

- **`cart_items` has no price column.** There is nowhere to store a submitted
  price, so "the cart trusted a client price" cannot be reintroduced by
  forgetting a check — it would take a migration. Asserted directly against the
  schema.
- **`Order::payment_status` throws on direct assignment.** A model guard fires
  on any write outside `OrderService` and survives `forceFill`, so no new
  endpoint can quietly mark an order paid.

Validation that *rejects* a bad price is weaker than a design with no field to
reject: a check can be skipped or forgotten on the next endpoint.

### A note on the cart's missing auth guard

The cart routes carry no `auth:sanctum`, which looks alarming and is correct —
guests must be able to shop, so the same endpoints serve both. The risk it
creates is real though: `carts.user_id` is a foreign key into `users`, and
`admins` is a separate table with its own id sequence, so if an admin token
resolved to a principal, admin #7 would read and write customer #7's cart.

It does not, because the default `sanctum` guard is bound to the `users`
provider — an admin token's `tokenable_type` cannot match, so `$request->user()`
is null and the request takes the guest path. That safety comes from a config
binding two files away from the route, so it is now pinned by a test.

---

## 3. Performance audit

| Area | Status | Notes |
|---|---|---|
| Database indexes | ✅ | 12 on `orders`, 12 on `products`, 8 on `payments`; composites match actual filter+sort pairs |
| N+1 queries | ✅ | `Model::preventLazyLoading()` outside production turns an N+1 into a test failure |
| API pagination | ✅ | Every listing paginated with a capped `per_page` |
| Redis caching | ✅ | Tagged caches for catalog, content, settings, reporting |
| Query optimisation | ✅ | Conditional aggregation, DB-side bucketing, `LIMIT` in SQL, correlated subqueries where a join would multiply rows |
| Image optimisation | ✅ | `next/image` with AVIF/WebP |
| CDN support | ✅ | Remote patterns configured from env, not hardcoded |
| Next.js caching | ✅ | ISR with tag-based revalidation |
| SSR / ISR | ✅ | Homepage 60s, CMS pages 300s, product pages dynamic (stock must be live) |
| Queue workers | ✅ | Redis, 4 priority queues, 3 retries, backoff, `restart: unless-stopped`, failed-jobs table |

The revalidation webhook fails closed on a missing secret and compares in
constant time.

---

## 4. Test coverage

**821 tests** across unit, feature, API, and end-to-end suites.

New in this audit:

- **`CompleteOrderLifecycleTest`** — the full journey the brief asks for, driven
  entirely over HTTP: register → login → browse → cart → checkout (all steps) →
  order → payment → verification → stock reduction → status progression →
  delivery. 66 assertions in one test.

  Every stage was already covered in isolation; what no other test asserted is
  that the *handoffs* work — that the order charges exactly what checkout
  quoted, and that stock moves exactly once across the whole journey rather than
  at both reservation and settlement. Integration bugs live in the seams.

- **`ClientTrustBoundaryTest`** — 14 tests attempting price, discount, stock,
  and payment-status manipulation through the real API.

- **`AdminTokenCartLeakTest`** — pins the cross-principal cart isolation
  described above, including a case that forces an `Admin` and a `User` to share
  a primary key.

---

## 5. Before you deploy

`php artisan app:production-check` verifies all of this and exits non-zero on
failure. The settings below are correct for local development and **unsafe in
production**:

```dotenv
APP_ENV=production
APP_DEBUG=false              # debug pages leak credentials and stack traces
APP_URL=https://...          # signed URLs and mail links depend on it
SESSION_SECURE_COOKIE=true   # or the admin session cookie travels in clear
CACHE_STORE=redis            # tags are required; file/database silently disable caching
QUEUE_CONNECTION=redis       # sync would run emails inside the web request
CORS_ALLOWED_ORIGINS=https://yourstore.com
```

Also confirm:

- `APP_KEY` is set (encrypted values and signed URLs depend on it).
- Every enabled payment gateway has real credentials and `sandbox` off — a
  gateway left in sandbox takes real orders and settles none of them.
- Each gateway has a webhook secret, or inbound webhooks are refused and
  payments stop settling by webhook.
- All migrations are run.
- The queue worker is running (`docker compose up -d queue`).
- TLS terminates in front of nginx, which already trusts proxy headers.
