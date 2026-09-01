# Payment Gateways

Taking money, and knowing for certain that it arrived.

The load-bearing rule in this phase is a single sentence: **a payment is marked
successful only by a server-to-server call to the processor.** Everything below
is either that rule or a consequence of it.

---

## 1. Why the browser is never believed

A customer returning from a hosted payment page arrives with a query string. It
is tempting to read `status=SUCCESS` and settle the order — SSLCommerz even puts
`status=VALID` in the POST body, and Stripe appends a session id to the success
URL.

That redirect travelled **through the customer's machine**. Its contents are
editable, its destination is guessable, and the URL is often visible in browser
history. A store that trusts it is a store where typing a URL is equivalent to
paying.

So the callback handler uses the request for exactly one thing: working out
*which transaction* is being reported. It then asks the gateway directly, using
credentials the customer does not have, and that answer is what settles
anything.

This is enforced structurally rather than by discipline. `PaymentService::settle()`
is the only method that may mark money as received, and it accepts only a
`PaymentVerification` — an object that can only be produced by a gateway's
`verify()`. There is deliberately **no method anywhere that takes a status from
a request**. A developer wanting to trust the browser would have to add one, which
is a visible change in a file about money rather than a quiet omission.

`PaymentFlowTest::a_forged_success_callback_does_not_settle_the_order` posts
every field a gateway might send, all claiming success, and asserts the order
stays unpaid because the gateway said otherwise.

---

## 2. The architecture

```
                    PaymentGatewayInterface
                              ▲
      ┌───────────┬───────────┼───────────┬────────────┐
      │           │           │           │            │
CashOnDelivery  SSLCommerz  bKash      Stripe    (your next one)

                              ▲
                    PaymentGatewayManager
                     (config/payment.php)
                              ▲
      ┌───────────┬───────────┼───────────┐
 PaymentService  OrderService  Checkout  Controllers
```

The brief asks that future gateways be addable *without changing core order
logic*. That property does not come from having an interface — it comes from the
core depending on **only** the interface and never on a concrete class.

`config/payment.php` is the single place the application names a gateway
implementation. `OrderService`, `PaymentService`, `CheckoutService`,
`RefundService`, and both controllers contain no reference to SSLCommerz, bKash,
or Stripe.

That claim is asserted, not just stated:

- `GatewayArchitectureTest::core_order_logic_never_names_a_concrete_gateway`
  greps the core files for each gateway class name.
- `a_brand_new_gateway_works_end_to_end_without_any_core_change` defines a
  `CryptoGateway` **inside the test file**, registers it at runtime, and drives a
  payment to a settled order. It is in no config, no container binding, and no
  application class. If adding a gateway required touching anything outside its
  own class, that test could not pass.

### Adding a gateway

1. Implement `PaymentGatewayInterface` (extending `AbstractGateway` for the HTTP
   plumbing, if it talks to a remote API).
2. Add one line to `config/payment.gateways`.
3. Add its credentials to the environment.

Nothing else.

### The seven methods

| Method | Purpose |
|---|---|
| `identifier()` | Stable slug, stored in `payments.gateway` |
| `displayName()` | What a shopper sees |
| `isAvailable()` | Enabled **and** credentials actually present |
| `initiate()` | Start a payment, return a `PaymentIntent` |
| `handleCallback()` | Interpret the customer's return — must delegate to `verify()` |
| `verify()` | **The authoritative status.** Server-to-server, side-effect free |
| `parseWebhook()` | Verify the signature, normalise the event |
| `refund()` | Reverse at the processor |

`verify()` must be safe to call repeatedly: duplicate callbacks, webhook
retries, admin re-checks and the reconciliation sweep all land there.

---

## 3. The eleven steps

|  | Step | Where |
|---|---|---|
| 1 | Create pending order | `OrderService::placeFromSession` |
| 2 | Create payment transaction | `OrderService::attachPayment` |
| 3 | Initiate payment | `PaymentService::initiate` |
| 4 | Redirect to gateway | the storefront, using the returned URL |
| 5 | Handle success | `handleCallback` → `verify` → `settle` |
| 6 | Handle failure | `handleCallback` → `verify` → `settle` |
| 7 | Handle cancellation | `handleCallback` → `verify` → `settle` |
| 8 | Verify server-side | `gateway->verify()`, always |
| 9 | Update payment | `settle()`, in a transaction |
| 10 | Update order | `settle()`, same transaction |
| 11 | Finalize stock | `settle()`, same transaction |

Steps 9–11 share one transaction because they are one fact. A payment marked
paid whose order stayed pending is a sale that reconciles against nothing, and a
shopper looking at an unconfirmed order they have been charged for.

Note that steps 5, 6, and 7 all route through step 8. The outcome the customer
came back on decides which *page* they land on; it does not decide whether they
paid. A shopper redirected to the success URL whose payment did not settle lands
on the failure page — the honest outcome, and the one that stops them believing
an unpaid order is complete.

### Initiation records before redirecting

The gateway's reference is written to the payment row *before* the browser
leaves. A fast gateway's webhook can arrive before the redirect completes, and
it would find no payment to attach itself to.

---

## 4. Three defences against duplicate settlement

Gateways retry aggressively — Stripe redelivers for days until it gets a 2xx.
Customers refresh the return page. Duplicate delivery is **ordinary**, not
exceptional.

**1. The unique index** on `payment_webhook_events (gateway, event_id)`. A
check-then-act in PHP cannot close this: two concurrent retries would both find
no prior row and both proceed. Only the database can reject the second.

**2. `settle()` short-circuits** on an already-settled payment, so even a
notification that gets past the first guard changes nothing.

**3. The row lock.** `settle()` re-reads the payment under `lockForUpdate()`,
because a callback and a webhook for the same payment routinely arrive within
milliseconds. Without it both would read `processing`, both would settle, and
the order would get two confirmations.

### A bug this found

The callback dedupe key originally included the payment's *stored* transaction
reference. But settling **overwrites** that reference with whatever the gateway
finally reported — so the first callback changed the very value the key was
derived from, the second delivery computed a different key, missed the unique
index, and was processed again.

Every gateway whose final reference differs from its session id — Stripe
included — would have got one free duplicate. The key now uses only the
request's own identifiers, which do not move. Pinned by
`a_duplicate_callback_is_deduplicated_even_when_settling_changed_the_reference`.

---

## 5. Webhooks

Three checks, each closing a different hole:

**Signature** — `parseWebhook()` throws unless the payload is provably from the
gateway. This happens *before* a `WebhookEvent` is constructed, which gives a
useful invariant: if you are holding a `WebhookEvent`, the signature checked out.

**Replay** — the unique index, as above. Stripe's timestamp tolerance (5 minutes)
additionally stops an attacker replaying a genuine, correctly-signed event
indefinitely.

**Re-verification** — even a correctly signed event does not settle anything.
The reference is taken out of it and the gateway is asked directly.

Two verifications sounds redundant and is not: **the signature proves origin,
the lookup proves the amount.** For several processors the signed envelope does
not cover the amount at all.
`a_signed_webhook_is_still_re_verified_with_the_gateway` sends a correctly signed
event claiming the full amount while the gateway reports a smaller capture, and
asserts the payment fails.

### Response codes are load-bearing

| Situation | Response | Why |
|---|---|---|
| Handled, duplicate, or unhandled type | **200** | Any non-2xx means "retry". An error for an event we ignore puts the endpoint into a permanent retry loop and eventually gets the webhook disabled. |
| Bad signature | **400** | Deliberately vague. Saying *why* — wrong secret, missing header, stale timestamp — is an oracle for constructing one that passes. |
| Our processing failed | **500** | So the gateway retries. Answering 200 would make it consider the notification delivered and never send it again, losing the record of a payment permanently. |

Webhook endpoints are **not rate limited**. Throttling drops legitimate
notifications about money, and an unsigned flood is already cheap to reject.

---

## 6. The four gateways

### Cash on delivery

Implements the same interface as the others, deliberately. Special-casing it in
the order pipeline would mean the next method gets an `if` too, and the
interface stops being the single entry point that makes the others safe.

**Arranged is not paid.** `initiate()` returns a *completed* intent — nothing
further is required of the customer, no page to redirect to. But `verify()`
returns **pending**, and keeps returning pending until a human records the
collection. Collapsing those would mark every cash order as paid at placement,
so revenue would count money nobody collected and the unpaid queue would always
be empty.

### SSLCommerz

Hosted page. The browser redirect includes `status=VALID` — **not read**. Only
`val_id` is taken, as a lookup key for the `validationserverAPI` call using the
store password. SSLCommerz's own documentation is explicit that skipping this
leaves a store open to fabricated callbacks.

IPNs are signed with `verify_sign` (MD5 over the listed fields plus the hashed
store password), compared with `hash_equals` — a `===` on a hash leaks position
information through timing.

### bKash

Two things make it different. Every call needs a rate-limited **grant token**, so
it is cached with a TTL shorter than the token's real lifetime. And payment is
**create-then-execute**: `/execute` is a *capturing* call and cannot be used as a
status check.

That split is why `handleCallback()` does not simply delegate to `verify()` here:
the callback executes once, while `verify()` uses the read-only
`/payment/status`. Routing retries through `/execute` is how a store charges
twice.

bKash publishes no webhook signature scheme, so `parseWebhook()` **refuses**
rather than trusting an unsigned notification.

### Stripe

The only gateway with a genuine HMAC-signed webhook, so it is where the
signature tests live. Verified against the **raw request body** — `json_decode`
then `json_encode` reorders keys and the bytes would never match.

Stripe uses integer minor units, matching this codebase exactly, so no
conversion happens on this path at all.

No SDK: the integration touches four endpoints, and `stripe/stripe-php` would
bring a dependency whose major versions move independently of ours into the part
of the system that handles money.

---

## 7. What is stored

`payments` carries everything the brief asks for — transaction id, gateway,
amount, currency, status, gateway response, paid-at — plus the lifecycle of a
*remote* payment: `initiated_at`, `verified_at`, `cancelled_at`,
`attempt_count`, `redirect_url`.

`verified_at` is deliberately "when a server-side verification last ran", not
"when a callback last arrived". A callback is an untrusted browser navigation;
the verification is the only evidence that means anything in a dispute.

**No card data, ever.** `card_brand` and `card_last_four` are display fragments
for a receipt line. A stored PAN would put this application in PCI scope and make
a database backup a breach.

Gateway responses are **sanitised before storage** — processors echo back more
than they should, and `gateway_response` is readable by any admin holding
`view_payments`. Matching is on key substrings (`password`, `secret`, `token`…)
and recurses into nested payloads, because a secret one level down is just as
exposed.

`payment_webhook_events` records every inbound notification, **including the
rejected ones**. One failed signature is noise; a run of them is someone probing
the endpoint, and that pattern is only visible if the attempts are stored.

---

## 8. Reconciliation

The case that costs a store real money: the customer paid, then closed the tab
before the redirect fired. The webhook usually covers it — but not every gateway
sends one, and webhooks get lost.

`PaymentService::reconcilePending()` re-verifies payments left in `processing`
past a window. One unreachable gateway does not stop the sweep; the rest of the
batch is other customers' money.

The window matters: a payment started ten seconds ago is a shopper still typing
their card number, not an abandonment.

---

## 9. Refunds

Reversed at the processor **first**, then recorded. Writing the refund row before
the gateway agreed would mean a refused reversal still incremented
`refunded_total` — the books would show money returned that the customer never
received, and the balance available for a later legitimate refund would be wrong.

Both happen inside one transaction, so a gateway failure rolls back and leaves no
trace of a refund that did not happen.

An asynchronous reversal is recorded `pending`, not `completed`. Telling a
customer their money is back while the processor has only queued it produces a
support call two days later.

Offline gateways are **not** asked to reverse anything —
`supportsRefunds()` returns false for cash, because asking a processor to reverse
a transaction it never had would fail, and failing would block a refund that has
already physically happened.

### A second bug this found

The refund idempotency guard relied on the unique index — which only fires at
`INSERT`, **after** the gateway call. A double-clicked refund button therefore
paid the customer twice at the processor and then quietly returned the first
refund row, hiding the second payout entirely.

The check now runs before the reversal, inside the transaction with the order row
locked. The unique index remains as the backstop. Pinned by
`a_double_clicked_refund_reverses_once`, which asserts `refundCalls === 1`.

---

## 10. Credentials

Environment variables only. These are **not** store settings: an admin-panel
field holding a Stripe secret key would put it in the database, in every backup,
and in front of anyone holding `manage_settings` — whereas branding, which *is* a
settings row, harms nobody if read.

Every remote gateway defaults to **disabled**. A fresh install must not be able
to offer a payment method whose credentials are absent, the failure mode being an
order that reports itself paid and never was.

`isAvailable()` checks that credentials are actually *present*, not merely that a
flag is set — so a half-configured gateway is absent from checkout rather than
offered and then failing at the moment of payment, which is the most expensive
point to discover a configuration error.

Sandbox and live are separate base URLs rather than a flag, so a misconfigured
environment fails to connect instead of quietly charging real cards from a
staging box.

---

## 11. Admin

| Endpoint | Permission |
|---|---|
| `GET /admin/payments` | `view_payments` |
| `GET /admin/payments/statistics` | `view_payments` |
| `GET /admin/payments/{payment}` | `view_payments` |
| `GET /admin/payments/{payment}/events` | `view_payments` |
| `GET /admin/payments/events/unverified` | `view_payments` |
| `POST /admin/payments/{payment}/verify` | `manage_payments` |

Filters: **gateway**, **status** (comma-separated), **date range**, **order
number**, plus free-text search across the transaction reference, payment uuid,
order number, and customer email. All applied in SQL — filtering a loaded
collection would make the pagination counts lie about how many matches exist.

Statistics report **captured** money, not attempted. Summing every row regardless
of status would report failed attempts as revenue, which is the single most
misleading figure a payments dashboard can show.

### There is no "mark as paid"

An admin can ask the gateway to **re-verify**; they cannot assert an outcome. An
endpoint letting staff set a payment to Paid would be the one hole in the rule
this whole phase is built around — and it would get used, because it is the
fastest way to close a support ticket.

`there_is_no_endpoint_that_marks_a_payment_paid_directly` asserts this against
the registered route table, so it catches such an endpoint being added under any
name.

---

## 12. Tests

111 tests across five suites:

| Suite | Covers |
|---|---|
| `PaymentFlowTest` | Success, failure, cancellation, duplicate callbacks, invalid callbacks, webhooks, amount mismatch, reconciliation |
| `StripeGatewayTest` | Real HMAC verification, replay window, secret rotation, session parsing, refunds, credential redaction |
| `GatewayArchitectureTest` | The contract, the extensibility proof, COD's semantics, SSLCommerz and bKash webhook posture |
| `Admin/PaymentManagementTest` | Every filter, statistics, event trail, permission separation |
| `PaymentRefundTest` | Gateway reversal, refusal leaving books untouched, pending refunds, offline path, ceilings, double-click |

A `FakeGateway` stands in for a processor in the flow tests so none of them make
network calls; the real gateways are tested separately for their own protocol
handling, where that *is* the code under test. A change to one processor's JSON
shape should break its own tests, not twenty unrelated ones.
