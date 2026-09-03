# Notifications

Thirteen notification types, one enum both audiences and every preference
check agree on, and an opt-out (not opt-in) model — because being unable to
reach a customer about a failed charge is worse than one unwanted email.

---

## 1. One vocabulary: `NotificationType`

`app/Enums/NotificationType.php` is the single source of truth for every
notification this application can send. A type that is not a case there
cannot be checked against a preference, muted, or listed in an account's
settings — which is what keeps the preference table and the actual set of
notifications from drifting apart. Two different classes can target two
different audiences for the same underlying event (`OrderPlacedNotification`
→ customer, `AdminNewOrderNotification`→ staff) without a mapping between them
existing anywhere, because `NotificationType::audience()` computes it from the
case instead.

| Type | Audience | Mutable | Default channels |
|---|---|---|---|
| `welcome` | Customer | No | mail, database |
| `order_placed` | Customer | No | mail, database |
| `order_confirmed` | Customer | Yes | mail, database |
| `order_shipped` | Customer | Yes | mail, database |
| `order_delivered` | Customer | Yes | mail, database |
| `order_cancelled` | Customer | Yes | mail, database |
| `payment_successful` | Customer | Yes | mail, database |
| `payment_failed` | Customer | No | mail, database |
| `refund_processed` | Customer | Yes | mail, database |
| `admin_new_order` | Admin | Yes | mail, database |
| `admin_payment_received` | Admin | Yes | database only |
| `admin_low_stock` | Admin | Yes | database only |
| `admin_failed_payment` | Admin | No | mail, database |

Admin alerts default to database-only except the two most actionable ones — a
badge for "stock is low" is enough; defaulting every admin alert to email
would turn a busy day into a mail storm nobody reads.

## 2. Immutable types bypass preferences entirely

Four types (`Welcome`, `OrderPlaced`, `PaymentFailed`, `AdminFailedPayment`)
are `isMutable() === false`. Their channels are never checked against a
stored preference, no matter what it contains — a customer cannot silently
miss "your payment failed," and `Welcome` has a simpler reason still: there is
no account to hold a preference until the welcome email is the thing that
establishes one exists.

## 3. Delivery mechanics

Every notification class mixes in `RespectsNotificationPreference`, which
turns each class's full, unfiltered channel list into the actual `via()`
Laravel calls by filtering it against `NotificationPreferenceService`. `via()`
returning `[]` is how Laravel expresses "send nothing" — a recipient who has
muted every channel for a type receives no dispatch, silently, no exception.

All notifications implement `ShouldQueue` and run on the `notifications`
queue (`onQueue('notifications')`), processed by the `queue` container
(`php artisan queue:work redis --queue=high,default,notifications,low ...`).
Because `SerializesModels` re-fetches a bare model on the worker and this
application runs `Model::shouldBeStrict()` outside production, every queued
notification's constructor calls `loadMissing()` for whatever relations its
mail/database representation needs — skipping this throws a
`MissingAttributeException` or `LazyLoadingViolation` on the worker, not in
the request that dispatched it.

A guest order has no `User` model to notify. Guest-facing notifications route
through `Notification::route('mail', $order->customer_email)->notify(...)`
instead of an Eloquent notifiable — the database channel is skipped
automatically, since there is no account to store a database notification
against.

## 4. What triggers what

| Event | Notification(s) |
|---|---|
| Account created | `WelcomeNotification` |
| Order placed | `OrderPlacedNotification` (customer) + `AdminNewOrderNotification` (every admin with `view_orders`) |
| Order status → Confirmed / Shipped / Delivered / Cancelled | matching `Order*Notification` |
| Payment settles | `PaymentSuccessfulNotification` + `AdminPaymentReceivedNotification` |
| Payment fails | `PaymentFailedNotification` + `AdminFailedPaymentNotification` |
| Refund created (first time — not a duplicate replay) | `RefundProcessedNotification` |
| Stock falls to or below reorder point | `AdminLowStockNotification` |

Order-lifecycle and low-stock notifications dispatch from
`AppServiceProvider::registerEventListeners()` — `SendOrderNotifications`,
`SendLowStockNotification`, `SendWelcomeNotification` — reacting to domain
events the same way `OrderPlaced`/`StockAdjusted` already did before this
phase. Payment and refund notifications dispatch directly from
`PaymentService`/`RefundService`, after the settling transaction commits, so
a notification is never sent for a state change that then rolled back.

## 5. API

**Customer** (`auth:sanctum`):

```
GET     /notifications                  — the account's database notifications
GET     /notifications/unread-count
POST    /notifications/read-all
PATCH   /notifications/{notification}/read
GET     /notifications/preferences      — every mutable type, with current per-channel state
PATCH   /notifications/preferences      — {type, channel, enabled}
```

**Admin** (same shape, scoped to the authenticated admin's own notifications):

```
GET     /admin/notifications
GET     /admin/notifications/unread-count
POST    /admin/notifications/read-all
PATCH   /admin/notifications/{notification}/read
GET     /admin/notifications/preferences
PATCH   /admin/notifications/preferences
```

Neither surface takes a notifiable id — both are scoped entirely to
`$request->user()` / the authenticated admin, so one account can never read or
mark another's notifications by guessing an id.

## 6. Preferences: opt-out, not opt-in

`notification_preferences` is polymorphic (`Admin` and `User` share it) and
records only *departures* from the default. The absence of a row for a given
(account, type, channel) means "enabled at the default" — so shipping a new
mutable notification type requires no backfill migration to turn it on for
existing accounts; it is simply on until someone opts out.
