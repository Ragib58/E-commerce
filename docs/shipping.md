# Shipping

Zoned rates on top of the flat per-method pricing that already existed —
without breaking a method that has never heard of a zone.

---

## 1. The two-tier model

A `ShippingMethod` (Standard, Express, ...) always has a flat `rate` and an
optional `free_above` threshold. That is the whole story for a store that never
configures a zone — the method prices itself.

A `ShippingZone` (Inside Dhaka, Outside Dhaka, ...) is an optional refinement.
Zones match a delivery address against JSON lists of countries, states,
cities, and postcodes (postcodes accept a trailing `*` wildcard, e.g. `12*`),
resolved in priority order — most specific first — via
`ShippingZoneService::resolveZone()`. A zone flagged `is_fallback` catches any
address nothing else matches, so "everywhere else" needs no exhaustive list.

`ShippingRate` joins a method to a zone with its own subtotal-banded price and
free-shipping threshold, so "Express costs 150 inside Dhaka, 300 outside" is a
handful of rows, not a rewrite of the method.

## 2. Resolution order — the one place this is decided

`ShippingZoneService::quote()` is called by checkout, order placement, and the
admin quote preview alike, so the answer can never diverge between what a
shopper is shown and what an order is actually charged:

1. Resolve the address to a zone (or none).
2. If the method has an active rate row for that zone whose subtotal band
   covers the order, use it.
3. Otherwise fall back to the method's own flat `rate` / `free_above`.

A method is never unavailable purely because no rate row exists for a zone —
only an explicit inactive rate row for that exact zone and subtotal band reads
as "not offered here." This is what lets an operator turn zoned pricing on
one method at a time instead of pricing every method for every zone before any
of them can be offered.

## 3. Free shipping

Two independent mechanisms reach the same `shipping_total: 0`, and either can
apply on its own:

- **Threshold.** A rate's (or method's) `free_above`, compared against the
  order subtotal.
- **Coupon.** A coupon flagged `free_shipping` waives the shipping charge
  outright — see [docs/coupons.md](coupons.md) — independent of any
  percentage or fixed discount the same coupon also carries.

## 4. Courier and tracking

An order carries `courier_name`, `tracking_number`, `tracking_url`, and
`dispatched_at`, set together via `OrderService::setTracking()` when an admin
moves an order to Shipped (`PATCH /admin/orders/{order}/status`). All three are
free text rather than foreign keys — a courier is a fact printed on the
parcel, not a reference to a row that might later be renamed — and the
customer-facing `OrderResource` exposes them under `tracking`.

Also stored on the order at placement time: `shipping_zone_id` and
`shipping_zone_name`, a snapshot of which zone priced the order — so a zone
later renamed or deleted does not change what a past order's receipt says it
was.

## 5. API

**Admin** (`permission:manage_shipping` / `view_shipping`):

```
GET|POST            /admin/shipping/methods
GET|PATCH|DELETE    /admin/shipping/methods/{method}
POST                /admin/shipping/methods/{method}/rates
DELETE              /admin/shipping/rates/{rate}
GET|POST            /admin/shipping/zones
GET|PATCH|DELETE    /admin/shipping/zones/{zone}
GET                 /admin/shipping/quote   — preview a quote for an address
```

**Storefront** (checkout, `auth:sanctum` where the checkout itself requires
it):

```
GET   /checkout/{token}/shipping-methods   — priced for the checkout's address
PUT   /checkout/{token}/shipping-method
PUT   /checkout/{token}/shipping-address
```

## 6. Example configuration

Two zones, one method priced differently in each:

```
Zone: "Inside Dhaka"   priority 10   cities: ["Dhaka"]
Zone: "Outside Dhaka"  priority 0    is_fallback: true

Method: "Standard Delivery"   rate: 100   free_above: 2000  (the base case)

Rate: Standard × Inside Dhaka    rate: 60   free_above: 1500
Rate: Standard × Outside Dhaka   rate: 120  free_above: null
```

An address in Dhaka resolves to the first zone and is quoted 60 (or free above
1500); an address in Chittagong falls through to the fallback zone and is
quoted 120; a store with only `Method` configured and no zones at all still
prices every address at 100, unchanged from before this phase.
