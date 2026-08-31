# Checkout and Orders

Turning a cart into an order, and running that order through its life.

The load-bearing decision in this phase is that **the server owns the checkout
sequence and every number in it**. A cart is an intention; an order is a
contract. Almost everything below follows from that distinction.

---

## 1. A cart stores no prices. An order stores nothing but.

The previous phase's rule was that `cart_items` has no price column, because a
cart must track the catalog — if a price rises, the shopper should see the new
one.

Orders invert it. `orders` and `order_items` carry the full breakdown — unit
price, list price, per-line discount, per-line tax, subtotal, shipping, grand
total — and none of it is ever recomputed on read.

The reason is the same in both directions: **there must be exactly one answer,
and it must be the right kind of answer for what the row means.**

- A cart line re-derives, so it cannot go stale.
- An order line snapshots, so it cannot be rewritten.

An invoice must render identically in five years. By then the product may have
been renamed, restructured into different variants, or archived. Joining to the
live catalog would silently rewrite history: the customer's receipt would stop
matching the box that arrived.

So `order_items` copies the product name, sku, variant name, attribute
selections, and thumbnail at placement. The foreign keys are kept as well —
"everything ever ordered of this product" is still one query — but they are for
*analysis*. The copied columns are *the record*.

This also means price manipulation is not defended against so much as made
unrepresentable: no request field maps to any money column on an order. The
figures are a snapshot the server took, not a document the client submitted.
`OrderCreationTest::a_submitted_price_cannot_reach_the_order` posts eight
different money fields at the placement endpoint and asserts the catalog's
figures survive.

---

## 2. The seven steps, and why the server holds them

1. Customer information
2. Shipping address
3. Billing address
4. Shipping method
5. Payment method
6. Order review
7. Place order

The obvious implementation keeps the current step in the client and posts
everything at the end. That makes step order a frontend concern — and a frontend
concern is not a constraint. A crafted request posts straight to step 7 having
never chosen a shipping method, and the order is created with a null shipping
cost.

Here each answer is persisted to `checkout_sessions` as it is given, and
`CheckoutStep::isSatisfiedBy()` decides whether a step's data is actually
present. A request that jumps ahead is refused, and the error names the step
that must be completed first so the client can navigate there:

```json
{
  "errors": {
    "checkout": ["Complete \"Your details\" first."],
    "required_step": ["customer"]
  }
}
```

Skipping is not a state the system can enter, rather than one it detects
afterwards.

Two further properties fall out of holding this server-side:

**Checkout is resumable.** A shopper who closes the tab at payment returns to
the session as they left it. Cart abandonment at checkout is expensive, and
losing a half-filled address form to a dropped connection is a self-inflicted
share of it.

**Changing an answer invalidates what depended on it.** Editing the shipping
address to a different *country* clears the chosen shipping method — the new
country may not be served, and carrying the choice forward would price the
order with a method no longer offered. Moving within one country does *not*
clear it, because the same carriers still serve it and sending the shopper back
a step for nothing is friction.

Any change to a priced input also clears the review acknowledgement. "You agreed
to this total" is only true of a total that was actually shown.

### The session stores choices, never money

`checkout_sessions.data` holds an address, a shipping method **id**, a payment
method. It holds no subtotal, no shipping cost, no total. A figure persisted at
step 4 and trusted at step 7 is a three-step window in which the catalog can
move, and a writable surface a crafted request can aim at. Every number is
recomputed on each read.

### Guest and registered are one path

Both produce a `checkout_sessions` row; the only difference is whether `user_id`
is set. Two separate flows would mean two places where an address is validated
and two where shipping is priced — and the guest path, being the less-tested
one, is where the disagreement would live.

A guest who signs in mid-checkout keeps their session and everything typed into
it. Signing in to use a saved card is a normal thing to do at step 5; losing the
address entered at step 2 for it is how a checkout gets abandoned.

---

## 3. The three failures order creation must prevent

### Duplicate orders

A double-clicked button, a retried request after a timeout, and a replayed
payload all present the same `Idempotency-Key` header. The unique index on
`orders.idempotency_key` is what stops the second one — a check-then-insert in
PHP cannot, because two requests can both pass the check before either inserts.

`OrderService::place()` catches the constraint violation and returns the order
that won, so the caller sees a success rather than an error for what is, from
the shopper's side, one order.

There are three layers, deliberately:

| Layer | Catches |
|---|---|
| `checkout_sessions.order_id` | The ordinary retry — cheapest, no exception |
| `orders.idempotency_key` unique index | Genuine concurrency |
| `throttle:checkout-place` | Retry storms |

The rate limit is a backstop, not the defence. A rate limit slows a double
submission down; it does not make it safe.

One subtlety worth stating, because it was a real bug found by the tests: a
*completed* session must still resolve. A client whose 201 was lost retries, and
must be handed the order it already placed. Rejecting the session as expired
would answer that retry with "start again" — inviting the shopper to order
twice, which is the exact failure the whole mechanism exists to prevent.

### Price manipulation

Covered in §1. There is no field to submit a price into, at any step.

### Stock race conditions

Every line's stock is decremented through `InventoryService`, which re-reads its
row under `lockForUpdate()` inside the placing transaction. Two orders for the
last unit serialise: the second blocks, then sees zero and fails.

**The lock is the correctness boundary.** Reservations (below) narrow the window
for the shopper's benefit; they are not what makes the system correct.

The whole placement is one transaction — the order, its lines, its addresses,
the stock decrements, the reservation commit, the cart clear, and the opening
audit row. A partial order is the one outcome worse than a failed one: a
customer charged for lines that were never recorded, or stock removed for an
order that does not exist.

---

## 4. Stock reservations

A cart deliberately does **not** reserve stock — reserving at add-to-cart would
let anyone deny the catalog to everyone else by filling a basket, and shoppers
sit on carts for days.

Checkout is different in the way that matters: bounded, intentional, and short.
A shopper who has entered an address and chosen a payment method is minutes from
placing, and losing the last unit at the final click is the worst moment to
discover it.

So the hold is taken **late** — at the review step, not at step 1 — and expires
fast (15 minutes). Expiry is what makes granting it defensible at all: an
abandoned checkout releases its units without anyone intervening.

### A reservation is not a decrement

`products.stock` is untouched while a reservation is live. Available-to-sell is
`stock` minus live reservations. Keeping them separate preserves the inventory
ledger's meaning — a `StockMovement` records goods that actually moved, and
writing one for a checkout later abandoned would fill the ledger with sales that
never happened and corrupt every reconciliation built on it.

Expired holds stop counting **immediately**, filtered in SQL, not when a sweeper
runs. If the sweeper were the mechanism rather than the tidy-up, availability
would depend on how recently a scheduled job last ran, and stock would sit
unsellable in the gap.

---

## 5. Order status

```
Pending ──┬─→ Confirmed ──┬─→ Processing ──┬─→ Packed ──→ Shipped ──┬─→ Delivered
          │               │                │                        │      │
          └───────────────┴────────────────┴──→ Cancelled           └──→ Returned
                                                     │                     │
                                                     └─────→ Refunded ←────┘
```

The map lives in `OrderStatus::allowedTransitions()` and is the only answer to
"can this order move there". Without one authoritative source, the question gets
answered independently in the admin controller, the customer controller, and the
refund path — and the answers drift. A shipped order that one path lets a
customer cancel is a parcel in a van with a refund already issued against it.

The graph is **forward-only with two exits**. An order does not go back from
Shipped to Packed: the physical event already happened, and a status that can
retreat makes the history meaningless. A mistake is corrected by moving forward,
exactly as the inventory ledger corrects with an opposing movement.

Notes on specific states:

- **Delivered is not terminal.** A delivered order can still be returned;
  modelling it as an endpoint would make returns unrepresentable.
- **Returned → Refunded is separate.** The goods coming back and the money going
  out are two events, often days apart. Collapsing them loses the window where
  the warehouse has the item but finance has not yet paid.
- **Pending may skip to Processing.** Stores that capture on payment confirm and
  begin picking in one motion; a mandatory intermediate click is ceremony.

### Only one code path may write a status

`OrderService::transitionTo()` validates against the map, writes the audit row,
restocks where required, stamps the lifecycle timestamp, and dispatches the
event — one transaction. A bare `$order->status = X` performs one fifth of that,
so `Order::booted()` **throws** on a direct write rather than documenting the
rule:

```php
$order->forceFill(['status' => OrderStatus::Delivered])->save();
// LogicException: Order status must be changed through OrderService…
```

The transition also re-reads the order under a row lock. Two admins clicking
"Ship" and "Cancel" simultaneously would otherwise both validate against the
same stale state and both write.

### Cancellation: two rules, not one

| | Admin | Customer |
|---|---|---|
| Pending | ✓ | ✓ |
| Confirmed | ✓ | ✓ |
| Processing | ✓ | ✗ |
| Packed | ✓ | ✗ |
| Shipped onward | ✗ | ✗ |

A customer's window closes earlier because a self-service cancellation past
Confirmed races the warehouse — staff may already be holding the item. The
refusal points to support rather than simply saying no; the request is
reasonable even when the button is not available.

Neither may cancel a shipped order. The parcel is with a carrier and the store
no longer controls it; the correct instrument is a **return**, which tracks the
goods coming back rather than pretending they never left.

### Restocking

Driven by `OrderStatus::holdsStock()`, a property of the status rather than a
flag on the order, so the two cannot disagree. Which lines actually restock is
tracked per line by `order_items.stock_was_reduced`, cleared as each is
returned — so a cancel followed by a refund cannot restock twice, and a digital
line never creates inventory from nothing.

Restocking can be declined (`"restock": false`) for goods damaged in the
warehouse. Silently returning them would put a broken product back on sale.

---

## 6. Payment status

Tracked separately from order status, because the two genuinely move
independently. A cash-on-delivery order ships while payment is Pending; a
prepaid order is Paid weeks before Delivered; a Delivered order can later be
Refunded without goods coming back.

```
Pending ──→ Paid ──┬─→ PartiallyRefunded ──→ Refunded
   │               └─→ Refunded
   └─→ Failed
```

`PartiallyRefunded` is a first-class state, not a boolean beside Refunded.
Partial refunds are ordinary — one line of five returned, a shipping fee waived
— and an order with money still owed to the store is in a materially different
position from one made whole.

### Payments are many rows, not one column

A customer whose card is declined twice before succeeding produces three
`payments` rows, and all three matter: the failures are what a fraud review
reads. A single mutable `payment_reference` on the order would erase exactly the
evidence a dispute needs.

**No card data is ever stored.** `card_brand` and `card_last_four` are display
fragments for a receipt line. A stored PAN would put this application in PCI
scope and make a database backup a breach.

No gateway is wired up in this phase. Offline methods (cash on delivery, bank
transfer) are fully functional. Online methods are declared, validated, and
routed to a deliberate refusal at the moment they are chosen — an order that
reports itself Paid because nothing rejected it is the worst available outcome.

---

## 7. Refunds

**A store can never refund more than it took.** `orders.refunded_total` is the
running sum, and every refund is checked against it inside a transaction with
the order row locked. Checking outside the lock is the classic over-refund bug:
two admins clicking "refund £50" on a £50 order both read "£50 refundable" and
both pay out.

An admin chooses **which lines and how many units**. They do not choose what a
unit is worth — that is computed from the order's stored unit price plus its
share of tax. Refunding the goods but keeping the tax on them shorts the
customer, and nobody notices until an auditor does.

Full versus partial is decided by arithmetic, not by which button was pressed.
Three partial refunds that happen to sum to the total have fully refunded the
order, and it says so.

---

## 8. Documents

Invoice and packing slip are each one Blade view, rendered to HTML for printing
and through Dompdf for download. Not two templates: a document whose printed
copy and PDF can differ is one that eventually will, and the discrepancy
surfaces in a tax audit rather than in review.

**The packing slip carries no prices at all.** It goes in the box, and a gift
order arriving with the price printed on the note inside is a real complaint.
This is not enforced by remembering to omit them — `packingSlipData()` does not
pass a money value, so a price cannot be added to that template by accident.

Neither document carries `admin_note`. An internal comment must never reach a
document the customer receives.

Dompdf runs with `isRemoteEnabled` **off**. The template renders
customer-supplied strings, and with remote loading a crafted value reaching an
`img` src would make the renderer issue HTTP requests from inside the network —
server-side request forgery via an invoice.

---

## 9. The disclosure boundary

`OrderResource` serves both audiences. Several fields belong only to staff: the
internal note, IP address and user agent, raw payment records, and the status
history including internal comments.

Those are gated on the **guard**, not on a flag passed by the caller:

```php
private function isAdminRequest(Request $request): bool
{
    return $request->user('admin-api') !== null
        || $request->user('admin') !== null;
}
```

A boolean argument would work until someone constructed the resource without it,
and the failure mode of that mistake is disclosing internal notes to the
customer they are about. Reading the guard means there is no argument to get
wrong.

Notes apply the same principle from the other side. The customer branch reads
the `customerVisibleNotes` relation, which filters in SQL — an internal note is
never *loaded* into a customer payload, rather than being loaded and then
filtered out one forgotten line away from serialisation. `order_notes`
`is_customer_visible` defaults to **false** in the column, the request, and the
controller: every layer fails closed.

### Guest order lookup

Order number **plus** the email it was placed with, both checked in the same
query. This is why `OrderNumberGenerator` produces a random component rather
than a sequence — with a guessable number the email would be the only secret,
and a support agent's inbox is full of those.

A wrong number and a wrong email return an identical response. Distinguishing
them would turn the endpoint into an oracle for which order numbers exist.

The number also draws from a Crockford-style alphabet with no `I`, `O`, `U`,
`0`, or `1`, so a customer reading it over the phone cannot produce an ambiguous
transcription.

---

## 10. Access control

| Permission | Grants |
|---|---|
| `view_orders` | Read orders, print invoices and packing slips |
| `update_orders` | Change status, add notes, record offline payments |
| `cancel_orders` | Cancel — releases stock, may owe a refund |
| `refund_orders` | Refund — moves money out of the business |

Split four ways because the jobs genuinely differ. A support agent reads orders
and adds notes all day; whoever answers the phone should not necessarily be able
to pay out. `OrderManagementTest` asserts each level does *not* grant the next.

`OrderPolicy` is the only policy reached by both an `Admin` and a `User` — an
order is the one record both legitimately read. It branches on `instanceof`,
which is safe precisely because Admin and User are separate models behind
separate guards: there is no column on either that could make one look like the
other.

A customer may only touch an order whose `user_id` is theirs.
`Order::belongsToUser()` deliberately does **not** match on email — a guest order
belongs to nobody, and matching by address would mean registering with a known
email discloses that person's guest order history.

Orders can never be deleted. `OrderPolicy::delete()` denies for everyone,
including Super Admin (`delete` is in `NON_BYPASSABLE_ABILITIES`). Accounting,
tax, and dispute resolution all require them to exist.

---

## 11. Endpoints

### Checkout — open to guests

| Method | Path | Purpose |
|---|---|---|
| POST | `/checkout` | Start or resume; returns `X-Checkout-Token` |
| GET | `/checkout/{token}` | The checkout, repriced |
| PUT | `/checkout/{token}/customer` | Step 1 |
| PUT | `/checkout/{token}/shipping-address` | Step 2 |
| PUT | `/checkout/{token}/billing-address` | Step 3 |
| GET | `/checkout/{token}/shipping-methods` | Available methods |
| PUT | `/checkout/{token}/shipping-method` | Step 4 |
| GET | `/checkout/payment-methods` | Offered methods |
| PUT | `/checkout/{token}/payment-method` | Step 5 |
| POST | `/checkout/{token}/review` | Step 6 — reserves stock |
| POST | `/checkout/{token}/place` | Step 7 — accepts `Idempotency-Key` |
| DELETE | `/checkout/{token}` | Abandon, releasing holds |

### Customer orders

| Method | Path | Purpose |
|---|---|---|
| GET | `/orders` | Own order history |
| GET | `/orders/{uuid}` | One order |
| GET | `/orders/{uuid}/track` | Tracking view |
| POST | `/orders/{uuid}/cancel` | Cancel, where allowed |
| POST | `/orders/lookup` | Guest lookup — number + email |

### Admin

| Method | Path | Permission |
|---|---|---|
| GET | `/admin/orders` | `view_orders` |
| GET | `/admin/orders/statistics` | `view_orders` |
| GET | `/admin/orders/{uuid}` | `view_orders` |
| GET | `/admin/orders/{uuid}/invoice` | `view_orders` |
| GET | `/admin/orders/{uuid}/packing-slip` | `view_orders` |
| PATCH | `/admin/orders/{uuid}/status` | `update_orders` |
| POST | `/admin/orders/{uuid}/notes` | `update_orders` |
| POST | `/admin/orders/{uuid}/payment` | `update_orders` |
| POST | `/admin/orders/{uuid}/cancel` | `cancel_orders` |
| POST | `/admin/orders/{uuid}/refund` | `refund_orders` |

Both documents serve HTML by default and PDF with `?format=pdf`.

---

## 12. Tests

170 tests across five suites:

| Suite | Covers |
|---|---|
| `OrderCreationTest` | Pricing, snapshots, idempotency, stock, reservations, guest vs registered |
| `OrderStatusTest` | The full transition matrix, audit trail, restocking, payment status |
| `CheckoutFlowTest` | Step enforcement, invalidation, session ownership, validation |
| `Admin/OrderManagementTest` | Search, filters, refunds, notes, documents, permission separation |
| `CustomerOrderTest` | Ownership, disclosure boundary, tracking, guest lookup, cancellation |

The transition matrix is a data provider covering 13 legal moves and 10 illegal
ones, so adding a state means adding rows rather than remembering to write a
test.

Several assertions are written from the attacker's side — posting prices at
every field, reaching for another customer's order, checking the whole
serialised body for internal strings rather than just the field they should be
in.
