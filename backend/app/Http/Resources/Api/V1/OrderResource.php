<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\AddressType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order.
 *
 * ## One resource, two audiences
 *
 * Customers and staff read the same order through this class, and several
 * fields belong only to staff: the internal note, the IP address and user
 * agent, the raw payment records, the full status history including internal
 * comments.
 *
 * Those are gated on {@see isAdminRequest()}, which asks the *guard* rather
 * than trusting a flag passed by the caller. A boolean argument would work
 * until someone constructs the resource without it, and the failure mode of
 * that mistake is disclosing internal notes to the customer they are about.
 * Reading the guard means the resource cannot be constructed into the wrong
 * shape — there is no argument to get wrong.
 *
 * Notes follow the same rule from the other side: the customer branch reads the
 * `customerVisibleNotes` relation, which filters in SQL, rather than filtering
 * a loaded collection at this layer.
 *
 * @mixin Order
 */
final class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAdmin = $this->isAdminRequest($request);

        return array_merge([
            'id' => $this->uuid,
            'order_number' => $this->order_number,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_colour' => $this->status->colour(),
            'status_description' => $this->status->customerDescription(),

            'payment_status' => $this->payment_status->value,
            'payment_status_label' => $this->payment_status->label(),
            'payment_status_colour' => $this->payment_status->colour(),

            'payment_method' => $this->payment_method->value,
            'payment_method_label' => $this->payment_method->label(),

            'customer' => [
                'name' => $this->customer_name,
                'email' => $this->customer_email,
                'phone' => $this->customer_phone,
                'is_guest' => $this->is_guest,
            ],

            /*
             * Money as integer minor units plus the currency code. Formatting
             * is the client's job — see App\Support\Money.
             */
            'totals' => [
                'subtotal' => $this->subtotal,
                'discount' => $this->discount_total,
                'tax' => $this->tax_total,
                'shipping' => $this->shipping_total,
                'total' => $this->grand_total,
                'refunded' => $this->refunded_total,
                'refundable' => $this->refundable_amount,
            ],

            'currency' => $this->currency,
            'tax_rate' => $this->tax_rate,
            'coupon_code' => $this->coupon_code,

            'shipping_method' => $this->shipping_method_name,
            'shipping_zone' => $this->shipping_zone_name,

            'tracking' => [
                'courier' => $this->courier_name,
                'number' => $this->tracking_number,
                'url' => $this->tracking_url,
                'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            ],

            /*
             * Progress along the fulfilment path. Null for orders that sit off
             * it — a cancelled order rendered as "step 7 of 7" would read as
             * complete.
             */
            'progress' => [
                'step' => $this->status->progressStep(),
                'total' => OrderStatus::progressTotal(),
                'is_terminal' => $this->status->isTerminal(),
            ],

            /*
             * What the *viewer* may do, decided server-side.
             *
             * A client that derives these itself would need the transition map,
             * and a second copy of that map is one that eventually disagrees —
             * showing a cancel button that then fails is worse than showing
             * none.
             */
            'permissions' => [
                'can_cancel' => $isAdmin
                    ? $this->isCancellable()
                    : $this->isCustomerCancellable(),
                'can_refund' => $isAdmin && $this->isRefundable(),
            ],

            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'item_count' => $this->whenCounted('items', fn (): int => $this->items_count),

            'shipping_address' => $this->addressOfType(AddressType::Shipping),
            'billing_address' => $this->addressOfType(AddressType::Billing),

            // The shopper's own note. Theirs to see on either surface.
            'customer_note' => $this->customer_note,

            'placed_at' => $this->placed_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ], $isAdmin ? $this->adminFields() : $this->customerFields());
    }

    /**
     * Fields only staff may see.
     *
     * @return array<string, mixed>
     */
    private function adminFields(): array
    {
        return [
            // Internal. Never in the customer branch.
            'admin_note' => $this->admin_note,

            'notes' => OrderNoteResource::collection($this->whenLoaded('notes')),

            // The full trail, including internal comments on each transition.
            'history' => OrderStatusHistoryResource::collection($this->whenLoaded('statusHistory')),

            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'refunds' => RefundResource::collection($this->whenLoaded('refunds')),

            /*
             * Provenance, for fraud review. Personal data with no customer-side
             * purpose — showing a shopper their own IP address tells them
             * nothing and widens what a compromised account discloses.
             */
            'meta' => [
                'ip_address' => $this->ip_address,
                'user_agent' => $this->user_agent,
                'cart_id' => $this->cart_id,
                'idempotency_key' => $this->idempotency_key,
            ],

            'customer_account' => $this->whenLoaded('user', fn (): ?array => $this->user === null ? null : [
                'id' => $this->user->uuid,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),

            // Cheap integrity check, surfaced so a corrupted order is visible
            // in the panel rather than only in a report.
            'totals_reconcile' => $this->totalsReconcile(),
        ];
    }

    /**
     * Fields for the customer's own view.
     *
     * @return array<string, mixed>
     */
    private function customerFields(): array
    {
        return [
            /*
             * Read through the filtered relation, not by filtering here.
             *
             * `customerVisibleNotes` applies `where is_customer_visible = true`
             * in SQL, so an internal note is never loaded into a payload the
             * customer receives — a filter at this layer would still put it in
             * memory one forgotten line away from being serialised.
             */
            'notes' => OrderNoteResource::collection($this->whenLoaded('customerVisibleNotes')),

            /*
             * The tracking timeline, with internal comments stripped.
             *
             * A customer should see that their order was confirmed and shipped
             * and when; they should not see "flagged for review, holding". The
             * resource decides that per row rather than the caller choosing a
             * relation, because both surfaces load the same `statusHistory`.
             */
            'history' => $this->whenLoaded(
                'statusHistory',
                fn (): array => $this->statusHistory
                    ->where('stream', OrderStatusHistory::STREAM_ORDER)
                    ->map(fn ($entry): array => [
                        'status' => $entry->to_status,
                        'label' => OrderStatus::tryFrom($entry->to_status)?->label(),
                        'description' => OrderStatus::tryFrom($entry->to_status)?->customerDescription(),
                        'created_at' => $entry->created_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
            ),
        ];
    }

    /**
     * One of the order's addresses, when the relation is loaded.
     */
    private function addressOfType(AddressType $type): mixed
    {
        return $this->whenLoaded('addresses', function () use ($type): ?array {
            $address = $this->addresses->firstWhere('type', $type);

            return $address === null
                ? null
                : (new OrderAddressResource($address))->toArray(request());
        });
    }

    /**
     * Whether this request is authenticated as staff.
     *
     * Asks the admin guard rather than accepting a flag. See the class
     * docblock: a boolean argument is a thing a caller can forget, and the
     * consequence of forgetting it here is disclosing internal notes.
     */
    private function isAdminRequest(Request $request): bool
    {
        return $request->user('admin-api') !== null
            || $request->user('admin') !== null;
    }
}
