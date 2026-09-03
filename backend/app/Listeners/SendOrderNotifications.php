<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Enums\PermissionType;
use App\Events\OrderPlaced;
use App\Events\OrderStatusChanged;
use App\Models\Admin;
use App\Models\Order;
use App\Notifications\AdminNewOrderNotification;
use App\Notifications\OrderCancelledNotification;
use App\Notifications\OrderConfirmedNotification;
use App\Notifications\OrderDeliveredNotification;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\OrderShippedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Turns order events into the notifications they imply.
 *
 * ## Why the mapping lives in one listener rather than inside OrderService
 *
 * OrderService's job ends at "the order exists" or "the status moved and was
 * recorded" — the docblocks on {@see OrderPlaced} and {@see OrderStatusChanged}
 * say so explicitly: "listeners decide what placement implies". Putting the
 * notification dispatch here rather than in the service means OrderService
 * stays testable and reviewable as pure order logic, and the full catalogue of
 * "what does this event cause" lives in one place a reviewer can read
 * top-to-bottom rather than being scattered across every place an order can
 * change.
 *
 * ## Guests are notified without an account
 *
 * `$order->user_id === null` routes through `Notification::route('mail', ...)`
 * — Laravel's anonymous notifiable — rather than through a `User` model. The
 * notification classes themselves do not care which path delivered them; see
 * each one's docblock.
 *
 * ## Queued, and idempotent by construction
 *
 * `ShouldQueue` on the listener means a slow mail provider cannot delay the
 * HTTP response that placed the order or moved its status. Each notification
 * class is independently queued too — see `onQueue('notifications')` on all
 * of them — so a listener failure retries the one notification that failed,
 * not the whole batch.
 */
final class SendOrderNotifications implements ShouldQueue
{
    public function handleOrderPlaced(OrderPlaced $event): void
    {
        $order = $event->order;

        $this->notifyCustomer($order, new OrderPlacedNotification($order));

        foreach ($this->adminsToNotify(PermissionType::ViewOrders) as $admin) {
            $admin->notify(new AdminNewOrderNotification($order));
        }
    }

    /**
     * Routes a status change to the matching customer notification.
     *
     * `shouldNotifyCustomer()` — see the event class — is checked first and is
     * what keeps Pending from generating a duplicate of the placement email;
     * the match below then picks the specific notification for states that do
     * warrant one. States with no case (Processing, Packed, Refunded) simply
     * fall through and send nothing, which is the correct behaviour for an
     * internal fulfilment step a customer does not need an email about.
     */
    public function handleOrderStatusChanged(OrderStatusChanged $event): void
    {
        if (! $event->shouldNotifyCustomer()) {
            return;
        }

        $order = $event->order;

        $notification = match ($event->status) {
            OrderStatus::Confirmed => new OrderConfirmedNotification($order),
            OrderStatus::Shipped => new OrderShippedNotification($order),
            OrderStatus::Delivered => new OrderDeliveredNotification($order),
            OrderStatus::Cancelled => new OrderCancelledNotification($order, $event->comment),
            default => null,
        };

        if ($notification !== null) {
            $this->notifyCustomer($order, $notification);
        }
    }

    /**
     * Send to the customer's account when one exists, or to their email
     * directly for a guest order.
     *
     * `loadMissing` rather than a bare `$order->user`: `Model::shouldBeStrict`
     * is enabled outside production, so an unloaded relation would throw a
     * LazyLoadingViolation here — this runs inside a queued job on a freshly
     * rehydrated model, not the request that already had it loaded.
     */
    private function notifyCustomer(Order $order, object $notification): void
    {
        $order->loadMissing('user');

        if ($order->user_id !== null && $order->user !== null) {
            $order->user->notify($notification);

            return;
        }

        Notification::route('mail', $order->customer_email)->notify($notification);
    }

    /**
     * Active admins holding the given permission.
     *
     * Loaded and filtered in PHP rather than queried — `hasPermission()`
     * resolves a role hierarchy and per-admin overrides that do not translate
     * into a single SQL WHERE clause. This runs inside a queued listener, not
     * a request, so the cost of loading the (typically small) admin table is
     * not on any customer-facing latency path.
     *
     * @return array<int, Admin>
     */
    private function adminsToNotify(PermissionType $permission): array
    {
        return Admin::query()
            ->active()
            ->get()
            ->filter(fn (Admin $admin): bool => $admin->hasPermission($permission))
            ->values()
            ->all();
    }
}
