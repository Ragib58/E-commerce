<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionType;
use App\Models\Admin;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Authorization for orders.
 *
 * ## Two principals, one policy
 *
 * Unlike the catalog policies, this one is reached by both an Admin and a
 * customer User — an order is the one record both kinds of account legitimately
 * read. Each method therefore branches on the actor's type rather than assuming
 * staff.
 *
 * The branch is on `instanceof`, not on a role or a flag. Admin and User are
 * separate models backed by separate tables and separate guards, so a customer
 * cannot reach the staff branch even if a token were confused — there is no
 * column on either model that could make one look like the other.
 *
 * ## The rule that matters
 *
 * A customer may only ever touch an order whose `user_id` is theirs.
 * {@see Order::belongsToUser()} deliberately does not match on email: a guest
 * order belongs to nobody, and matching by address would mean registering with
 * a known email discloses that person's guest order history.
 */
final class OrderPolicy
{
    /**
     * Listing orders.
     *
     * A customer always may — their own list is scoped by `user_id` in the
     * controller's query, so "view any" here means "view your own index", not
     * "view every order in the store".
     */
    public function viewAny(Admin|User $actor): bool
    {
        if ($actor instanceof User) {
            return true;
        }

        return $actor->hasPermission(PermissionType::ViewOrders);
    }

    /**
     * Reading one order.
     */
    public function view(Admin|User $actor, Order $order): bool
    {
        if ($actor instanceof User) {
            return $order->belongsToUser($actor);
        }

        return $actor->hasPermission(PermissionType::ViewOrders);
    }

    /**
     * Changing an order's status, tracking, or notes.
     *
     * Staff only. A customer's single mutating action is cancellation, which
     * has its own ability below — letting them reach `update` would put the
     * status endpoint within their reach.
     */
    public function update(Admin|User $actor, Order $order): bool
    {
        return $actor instanceof Admin
            && $actor->hasPermission(PermissionType::UpdateOrders);
    }

    /**
     * Cancelling.
     *
     * Both principals may, under different rules. A customer's window closes
     * earlier — see OrderStatus::isCustomerCancellable: past Confirmed, staff
     * may already be holding the item, and a self-service cancellation would
     * race the warehouse.
     *
     * The status check appears here *and* in OrderService. That is deliberate
     * duplication: the policy decides whether the button is offered, the
     * service decides — with the row locked — whether the write is legal, and
     * only the second can be correct under concurrency.
     */
    public function cancel(Admin|User $actor, Order $order): Response
    {
        if ($actor instanceof User) {
            if (! $order->belongsToUser($actor)) {
                // Same message as a missing order, so this cannot be used to
                // probe which order numbers exist.
                return Response::denyWithStatus(404);
            }

            if (! $order->isCustomerCancellable()) {
                return Response::deny(
                    'This order is already being prepared and can no longer be cancelled online. Contact us and we will help.',
                );
            }

            return Response::allow();
        }

        if (! $actor->hasPermission(PermissionType::CancelOrders)) {
            return Response::deny('You do not have permission to cancel orders.');
        }

        if (! $order->isCancellable()) {
            return Response::deny(sprintf(
                'An order that is %s can no longer be cancelled.',
                strtolower($order->status->label()),
            ));
        }

        return Response::allow();
    }

    /**
     * Refunding.
     *
     * Staff only, and gated on its own permission. Refunding moves money out of
     * the business — a support role that can advance an order through
     * fulfilment should not necessarily hold it.
     */
    public function refund(Admin|User $actor, Order $order): Response
    {
        if (! $actor instanceof Admin) {
            return Response::denyWithStatus(403);
        }

        if (! $actor->hasPermission(PermissionType::RefundOrders)) {
            return Response::deny('You do not have permission to refund orders.');
        }

        if (! $order->isRefundable()) {
            return Response::deny(
                $order->refundable_amount <= 0
                    ? 'This order has already been fully refunded.'
                    : 'This order is not in a state that can be refunded.',
            );
        }

        return Response::allow();
    }

    /**
     * Printing or downloading an invoice or packing slip.
     *
     * Reading, not writing, so `view_orders` is enough — a warehouse account
     * that can see orders can print the slip it needs to pack one.
     */
    public function viewDocuments(Admin|User $actor, Order $order): bool
    {
        if ($actor instanceof User) {
            return $order->belongsToUser($actor);
        }

        return $actor->hasPermission(PermissionType::ViewOrders);
    }

    /**
     * Deleting an order.
     *
     * Nobody, ever. There is no route for it, and this method exists to make
     * that explicit rather than leaving the ability undefined and therefore
     * subject to the Super Admin bypass. Orders are soft-deleted at most:
     * accounting, tax, and dispute resolution all require them to exist.
     */
    public function delete(Admin|User $actor, Order $order): Response
    {
        return Response::deny(
            'Orders cannot be deleted. Cancel or refund the order instead — its history must be retained.',
        );
    }
}
