# Coupons

Every rule a coupon can carry, validated entirely on the backend, twice.

---

## 1. Nothing about a coupon is trusted from the client

A cart request can supply a code; it cannot supply a discount, a currency
amount, or an "applied: true." `CouponService` is the only class that decides
whether a code is usable and what it's worth, and it decides that fresh from
`coupons`, `coupon_usages`, and the cart's own catalog-derived totals — never
from anything the request body says about the coupon itself.

`Coupon` (the model) answers questions about a coupon's *shape* — percentage
or fixed, does it apply to everything. It does not decide whether a given
cart, shopper, and moment make it *usable*, and it does not compute a
discount — both depend on state the model cannot see (the cart's contents, the
shopper's order history, a lock on `used_count`), so both live in the service.

## 2. Rules, all enforced server-side

| Rule | Column / mechanism |
|---|---|
| Percentage or fixed discount | `type`, `value` |
| Maximum discount | `max_discount` — caps a percentage coupon's payout |
| Minimum order amount | `min_order_amount` — checked against the cart subtotal |
| Product-specific | `coupon_product` pivot, with an `is_excluded` flag |
| Category-specific | `category_coupon` pivot, with `includes_descendants` |
| User-specific | `coupon_user` pivot, gated by `user_restricted` |
| First-order only | `first_order_only`, checked against both order history and, for a guest, `orders.customer_email` |
| Expiry / activation window | `starts_at`, `expires_at` |
| Usage limit (total) | `usage_limit` vs `used_count` |
| Per-user usage limit | `per_user_limit`, counted from `coupon_usages` |
| Free shipping | `free_shipping` — independent of any percentage/fixed value the same coupon also carries |

Product and category scoping combine as a union, not an intersection: a
coupon naming both product A and category B discounts a line if it matches
*either* — the ordinary reading of two scopes configured together, and an
excluded product or category is skipped even if it would otherwise match. A
coupon with `applies_to_all` and no product/category rows discounts the whole
eligible subtotal.

## 3. Two-phase redemption

Cart-level coupon entry (`POST /cart/coupon`) only **previews** — it validates
and reports a discount, but changes nothing durable. Redemption happens once,
at order placement, in two steps inside `OrderService::createOrder()`'s
transaction:

1. **`redeemPending()`** — locks the coupon row (`lockForUpdate()`),
   re-validates every rule from scratch (a coupon can expire or hit its limit
   between cart preview and checkout), and increments `used_count`. Runs
   *before* the order row exists, because the order's totals need the
   discount amount to be computed before insert.
2. **`recordRedemption()`** — writes the append-only `coupon_usages` ledger
   row, which needs the order's id and so can only run *after* insert.

Both calls share the outer transaction, so a rollback (a failed stock check,
a later error) undoes the usage counter and the ledger row together — a
coupon is never left "spent" against an order that never happened. The lock
taken in step 1 is what makes a race between two concurrent redemptions of a
near-exhausted coupon safe: the second waits for the first to commit or roll
back rather than both reading the same `used_count`.

## 4. Totals

`OrderService::calculateTotals()`'s `discount` figure is the coupon discount
only — a catalog line discount (a product's sale price) is already netted
into each line's price and therefore into `subtotal`. The invariant every
order satisfies:

```
subtotal − discount_total + tax_total + shipping_total = grand_total
```

A coupon's `free_shipping` flag zeroes `shipping_total` before this sum runs,
the same way a threshold-based free-shipping rule does — see
[docs/shipping.md](shipping.md).

## 5. API

**Admin** (`permission:manage_coupons` / `view_coupons`):

```
GET|POST            /admin/coupons
GET|PATCH|DELETE    /admin/coupons/{coupon}
GET                 /admin/coupons/{coupon}/usages   — the redemption ledger
```

**Storefront:**

```
GET    /coupons              — public, active, currently-listed coupons only
POST   /cart/coupon           — apply or clear; validated immediately, discount previewed
```

Applying an unknown or currently-invalid code is a `422` with a message under
`errors.coupon_code` — the cart's stored `coupon_code` is only set once the
code has actually validated, so a rejected code is never silently carried
into checkout. Every rule violation maps to a specific, shopper-facing
message ("This coupon requires a minimum order of ...", "That coupon has
expired.") except a coupon that exists but is restricted to someone else,
which is deliberately worded identically to "not valid" — confirming a
guessed code exists at all is a disclosure a shopper probing for one should
not get.
