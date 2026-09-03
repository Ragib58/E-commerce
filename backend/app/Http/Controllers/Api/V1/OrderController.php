<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Services\OrderService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A customer's own orders.
 *
 * ## Two ways in, one authorization rule
 *
 * A signed-in customer reads their orders by `user_id`. A guest has no account,
 * so their order is reached by **order number plus the email it was placed
 * with** — both, always. That pairing is why OrderNumberGenerator produces a
 * random reference rather than a sequence: with a guessable number, the email
 * would be the only secret, and a support agent's inbox is full of those.
 *
 * Neither path ever trusts a uuid alone. {@see OrderPolicy} decides ownership,
 * and the guest lookup re-checks the email inside the query rather than after
 * loading — a check applied after the fact is one that can be skipped by a
 * later refactor.
 *
 * ## What a customer sees
 *
 * OrderResource decides that by asking the guard, not by a flag passed here.
 * Internal notes, payment records, and provenance metadata never enter a
 * customer payload — see that class.
 */
final class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OrderService $orders,
    ) {}

    /**
     * GET /orders
     *
     * The signed-in customer's order history, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->errorResponse(
                message: 'Sign in to view your orders.',
                status: 401,
                code: 'UNAUTHENTICATED',
            );
        }

        $orders = Order::query()
            ->forUser((int) $user->getKey())
            ->withListingRelations()
            // A list view loads items only to count them; the lines themselves
            // are fetched on the detail page.
            ->latest('id')
            ->paginate($this->perPage($request));

        return $this->successResponse(
            data: OrderResource::collection($orders),
            message: 'Orders retrieved.',
        );
    }

    /**
     * GET /orders/{order}
     *
     * One order in full. Bound by uuid; the policy decides whether this
     * customer owns it.
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        $order->load([
            'items.product',
            'addresses',
            'statusHistory',
            // The filtered relation, so an internal note is never loaded into
            // a payload the customer receives.
            'customerVisibleNotes',
        ]);

        return $this->successResponse(
            data: new OrderResource($order),
            message: 'Order retrieved.',
        );
    }

    /**
     * POST /orders/lookup
     *
     * Guest order lookup: order number plus the email it was placed with.
     *
     * POST rather than GET because the email is a credential here, and a
     * credential in a query string ends up in server logs, browser history, and
     * the Referer header of every link on the page it loads.
     */
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_number' => ['required', 'string', 'max:32'],
            'email' => ['required', 'email:rfc', 'max:191'],
        ]);

        $order = Order::query()
            ->where('order_number', $validated['order_number'])
            /*
             * Both conditions in the query, not one here and one after loading.
             * A post-hoc check is one a later refactor can drop; a WHERE clause
             * cannot be accidentally skipped.
             */
            ->whereRaw('LOWER(customer_email) = ?', [strtolower($validated['email'])])
            ->with(['items.product', 'addresses', 'statusHistory', 'customerVisibleNotes'])
            ->first();

        if ($order === null) {
            /*
             * Deliberately identical whether the number is wrong, the email is
             * wrong, or both. Distinguishing them would turn this endpoint into
             * an oracle for whether a given order number exists.
             */
            return $this->errorResponse(
                message: 'We could not find an order matching those details.',
                status: 404,
                code: 'ORDER_NOT_FOUND',
            );
        }

        return $this->successResponse(
            data: new OrderResource($order),
            message: 'Order retrieved.',
        );
    }

    /**
     * GET /orders/{order}/track
     *
     * The tracking view: where the order is, and what happens next.
     *
     * Lighter than the full order on purpose — a tracking page is checked
     * repeatedly and does not need the line items or the addresses.
     */
    public function track(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        $order->load('statusHistory');

        return $this->successResponse(
            data: [
                'order_number' => $order->order_number,

                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'status_description' => $order->status->customerDescription(),

                'progress' => [
                    'step' => $order->status->progressStep(),
                    'total' => OrderStatus::progressTotal(),
                    'is_terminal' => $order->status->isTerminal(),
                ],

                'tracking' => [
                    'courier' => $order->courier_name,
                    'number' => $order->tracking_number,
                    'url' => $order->tracking_url,
                    'dispatched_at' => $order->dispatched_at?->toIso8601String(),
                ],

                'shipping_method' => $order->shipping_method_name,

                /*
                 * The timeline with internal comments stripped. A customer sees
                 * that their order was confirmed and when; they do not see
                 * "flagged for review, holding".
                 */
                'timeline' => $order->statusHistory
                    ->where('stream', OrderStatusHistory::STREAM_ORDER)
                    ->map(fn ($entry): array => [
                        'status' => $entry->to_status,
                        'label' => OrderStatus::tryFrom($entry->to_status)?->label(),
                        'description' => OrderStatus::tryFrom($entry->to_status)?->customerDescription(),
                        'created_at' => $entry->created_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all(),

                'placed_at' => $order->placed_at?->toIso8601String(),
                'shipped_at' => $order->shipped_at?->toIso8601String(),
                'delivered_at' => $order->delivered_at?->toIso8601String(),
            ],
            message: 'Tracking retrieved.',
        );
    }

    /**
     * POST /orders/{order}/cancel
     *
     * A customer cancelling their own order.
     *
     * The window is narrower than an admin's — see
     * OrderStatus::isCustomerCancellable. Past Confirmed, staff may already be
     * holding the item, and a self-service cancellation would race the
     * warehouse. The message points to support rather than simply refusing,
     * because the shopper's request is reasonable even when the button is not
     * available.
     */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        $this->authorize('cancel', $order);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:512'],
        ]);

        $user = $request->user();

        $order = $this->orders->cancel(
            order: $order,
            actor: $user,
            reason: $validated['reason'] ?? 'Cancelled by the customer.',
        );

        $order->load(['items', 'addresses', 'statusHistory', 'customerVisibleNotes']);

        return $this->successResponse(
            data: new OrderResource($order),
            message: 'Your order has been cancelled.',
        );
    }

    /**
     * Page size, bounded.
     *
     * An unbounded `per_page` is a cheap way to make the server assemble
     * thousands of orders with their relations in one request.
     */
    private function perPage(Request $request): int
    {
        return max(1, min(50, (int) $request->integer('per_page', 15)));
    }
}
