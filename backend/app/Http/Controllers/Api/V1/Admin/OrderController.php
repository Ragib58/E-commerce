<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\RefundOrderRequest;
use App\Http\Requests\Api\V1\Admin\StoreOrderNoteRequest;
use App\Http\Requests\Api\V1\Admin\UpdateOrderStatusRequest;
use App\Http\Resources\Api\V1\OrderNoteResource;
use App\Http\Resources\Api\V1\OrderResource;
use App\Http\Resources\Api\V1\RefundResource;
use App\Models\Order;
use App\Services\InvoiceService;
use App\Services\OrderService;
use App\Services\RefundService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Order administration.
 *
 * Every route reaching this controller has already passed an admin token, an
 * active account, a current password, and an explicit permission — see
 * routes/api/v1.php. Permissions are split four ways because the jobs genuinely
 * differ: a support agent reads orders and adds notes all day (`view_orders`,
 * `update_orders`), while cancelling and refunding move money and stock
 * (`cancel_orders`, `refund_orders`).
 *
 * The controller stays thin. Status transitions, refunds, and restocking are
 * all transactional operations with audit and stock consequences, so they live
 * in services where the transaction boundary is visible.
 */
final class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly OrderService $orders,
        private readonly RefundService $refunds,
        private readonly InvoiceService $invoices,
    ) {}

    /**
     * GET /admin/orders
     *
     * The order queue: searchable, filterable, paginated.
     *
     * Filtering happens in SQL throughout. Loading orders and filtering a
     * collection would make the pagination counts lie about how many matches
     * exist, which is the kind of bug that only shows up on page two.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', 'string'],
            'payment_status' => ['nullable', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'sort' => ['nullable', 'string', 'in:newest,oldest,total_high,total_low'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Order::query()->withListingRelations();

        $query->search($validated['search'] ?? null);

        /*
         * Statuses arrive as a comma-separated list, so the queue can show
         * "everything still to pick" — pending, confirmed, processing — in one
         * view. Unknown values are dropped rather than erroring: a stale
         * bookmark should return a sensible list, not a validation failure.
         */
        if (($statuses = $this->enumList($validated['status'] ?? null, OrderStatus::class)) !== []) {
            $query->withStatus($statuses);
        }

        if (($paymentStatuses = $this->enumList($validated['payment_status'] ?? null, PaymentStatus::class)) !== []) {
            $query->withPaymentStatus($paymentStatuses);
        }

        $query->placedBetween(
            isset($validated['from']) ? Carbon::parse($validated['from']) : null,
            isset($validated['to']) ? Carbon::parse($validated['to']) : null,
        );

        match ($validated['sort'] ?? 'newest') {
            'oldest' => $query->oldest('id'),
            'total_high' => $query->orderByDesc('grand_total'),
            'total_low' => $query->orderBy('grand_total'),
            default => $query->latest('id'),
        };

        $orders = $query->paginate((int) ($validated['per_page'] ?? 25));

        return $this->successResponse(
            data: OrderResource::collection($orders),
            message: 'Orders retrieved.',
            // The filter vocabulary travels with the list, so the panel's
            // dropdowns are built from the enum rather than hardcoded in the
            // frontend — the same "fully dynamic" rule the rest of the app
            // follows.
            meta: [
                'filters' => [
                    'statuses' => OrderStatus::options(),
                    'payment_statuses' => PaymentStatus::options(),
                ],
            ],
        );
    }

    /**
     * GET /admin/orders/{order}
     */
    public function show(Order $order): JsonResponse
    {
        $order->load([
            'items.product',
            'addresses',
            'statusHistory',
            'notes',
            'payments',
            'refunds',
            'user',
            'shippingMethod',
        ]);

        return $this->successResponse(
            data: new OrderResource($order),
            message: 'Order retrieved.',
        );
    }

    /**
     * PATCH /admin/orders/{order}/status
     *
     * Move an order along its lifecycle.
     *
     * Legality is decided inside OrderService against the transition map, with
     * the row locked — two admins clicking "Ship" and "Cancel" at the same
     * moment must not both succeed.
     */
    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        $status = OrderStatus::from($request->string('status')->toString());

        /*
         * Tracking is recorded before the transition, so a customer notified of
         * the move to Shipped can already see the number. Doing it afterwards
         * would email "your order has shipped" with nothing to track.
         */
        if ($request->filled('tracking_number') || $request->filled('courier_name')) {
            $this->orders->setTracking(
                order: $order,
                trackingNumber: $request->input('tracking_number'),
                trackingUrl: $request->input('tracking_url'),
                courierName: $request->input('courier_name'),
                actor: $request->user('admin-api'),
            );
        }

        $order = $this->orders->transitionTo(
            order: $order,
            target: $status,
            actor: $request->user('admin-api'),
            comment: $request->input('comment'),
            // Defaults to true: a cancelled order normally restocks, and an
            // admin who omits the field means the ordinary case.
            restock: $request->boolean('restock', true),
        );

        $order->load(['items', 'addresses', 'statusHistory', 'notes', 'payments', 'refunds']);

        return $this->successResponse(
            data: new OrderResource($order),
            message: sprintf('Order marked %s.', strtolower($status->label())),
        );
    }

    /**
     * POST /admin/orders/{order}/cancel
     *
     * Separate from the status endpoint because it carries a distinct
     * permission. Cancelling releases stock and may owe a refund — a support
     * role that can advance an order through fulfilment should not necessarily
     * be able to call one off.
     */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        $this->authorize('cancel', $order);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:512'],
            'restock' => ['sometimes', 'boolean'],
        ]);

        $order = $this->orders->cancel(
            order: $order,
            actor: $request->user('admin-api'),
            reason: $validated['reason'] ?? null,
        );

        $order->load(['items', 'addresses', 'statusHistory', 'notes', 'payments', 'refunds']);

        return $this->successResponse(
            data: new OrderResource($order),
            message: 'Order cancelled.',
        );
    }

    /**
     * POST /admin/orders/{order}/refund
     *
     * Full or partial. RefundService owns the ceiling check, which runs with
     * the order row locked so two admins cannot both refund the same balance.
     */
    public function refund(RefundOrderRequest $request, Order $order): JsonResponse
    {
        $this->authorize('refund', $order);

        $refund = $this->refunds->refund(
            order: $order,
            amount: $request->input('amount') !== null ? (int) $request->input('amount') : null,
            lines: $request->input('lines'),
            actor: $request->user('admin-api'),
            reason: $request->string('reason')->toString(),
            restock: $request->boolean('restock', false),
            idempotencyKey: $this->idempotencyKey($request),
        );

        $order->refresh()->load(['items', 'addresses', 'statusHistory', 'notes', 'payments', 'refunds']);

        return $this->successResponse(
            data: [
                'refund' => new RefundResource($refund),
                'order' => new OrderResource($order),
            ],
            message: 'Refund issued.',
            status: 201,
        );
    }

    /**
     * POST /admin/orders/{order}/payment
     *
     * Record a payment received outside the application — a bank transfer that
     * cleared, cash handed to a courier.
     */
    public function markPaid(Request $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:512'],
        ]);

        $order = $this->orders->markPaid(
            order: $order,
            actor: $request->user('admin-api'),
            comment: $validated['comment'] ?? null,
        );

        $order->load(['items', 'addresses', 'statusHistory', 'notes', 'payments', 'refunds']);

        return $this->successResponse(
            data: new OrderResource($order),
            message: 'Payment recorded.',
        );
    }

    /**
     * POST /admin/orders/{order}/notes
     *
     * Add a note to the thread.
     *
     * `is_customer_visible` defaults to false in three places — the column, the
     * request, and here. An internal note surfaced to the customer it is about
     * is a serious incident, so every layer fails closed.
     */
    public function storeNote(StoreOrderNoteRequest $request, Order $order): JsonResponse
    {
        $this->authorize('update', $order);

        $isVisible = $request->boolean('is_customer_visible', false);

        // Refused rather than silently ignored: "notify" on a hidden note is
        // more likely a mistake about visibility than about notification, and
        // quietly dropping the notification would hide the mistake.
        if ($request->boolean('notify_customer') && ! $isVisible) {
            return $this->errorResponse(
                message: 'An internal note cannot be emailed to the customer. Mark it customer-visible first.',
                status: 422,
                code: 'NOTE_NOT_VISIBLE',
            );
        }

        $note = $this->orders->addNote(
            order: $order,
            body: $request->string('body')->toString(),
            author: $request->user('admin-api'),
            isCustomerVisible: $isVisible,
        );

        return $this->successResponse(
            data: new OrderNoteResource($note),
            message: $isVisible ? 'Note added and shared with the customer.' : 'Internal note added.',
            status: 201,
        );
    }

    /**
     * GET /admin/orders/{order}/invoice
     *
     * HTML by default, PDF with `?format=pdf`.
     *
     * One document, two renderings of the same Blade view — an invoice whose
     * printed copy and PDF can differ is one that eventually will.
     */
    public function invoice(Request $request, Order $order): Response|StreamedResponse
    {
        return $this->document($request, $order, 'invoice');
    }

    /**
     * GET /admin/orders/{order}/packing-slip
     *
     * Carries no prices at all — it goes in the box. See the template.
     */
    public function packingSlip(Request $request, Order $order): Response|StreamedResponse
    {
        return $this->document($request, $order, 'packing-slip');
    }

    /**
     * Render an order document in the requested format.
     */
    private function document(Request $request, Order $order, string $type): Response|StreamedResponse
    {
        $wantsPdf = $request->query('format') === 'pdf';

        if ($wantsPdf && ! $this->invoices->supportsPdf()) {
            /*
             * A clear 503 rather than a 500 from a missing class. PDF rendering
             * is an optional dependency, and an admin should be told the
             * package is absent rather than shown a stack trace.
             */
            return response()->json([
                'success' => false,
                'message' => 'PDF generation is not available on this installation.',
                'code' => 'PDF_UNAVAILABLE',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($wantsPdf) {
            $pdf = $type === 'invoice'
                ? $this->invoices->invoicePdf($order)
                : $this->invoices->packingSlipPdf($order);

            return response($pdf, Response::HTTP_OK, [
                'Content-Type' => 'application/pdf',
                // `attachment` so the browser downloads rather than renders:
                // an admin clicking "download" expects a file.
                'Content-Disposition' => sprintf(
                    'attachment; filename="%s"',
                    $this->invoices->filename($order, $type),
                ),
            ]);
        }

        $html = $type === 'invoice'
            ? $this->invoices->invoiceHtml($order)
            : $this->invoices->packingSlipHtml($order);

        return response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }

    /**
     * GET /admin/orders/statistics
     *
     * Headline figures for the dashboard.
     */
    public function statistics(Request $request): JsonResponse
    {
        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : null;
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : null;

        $scoped = fn () => Order::query()->placedBetween($from, $to);

        return $this->successResponse(
            data: [
                'total_orders' => $scoped()->count(),

                /*
                 * Revenue excludes cancelled, refunded, and returned orders —
                 * decided by OrderStatus::countsAsRevenue rather than by a list
                 * of statuses written here, so a new state cannot silently
                 * start counting as a sale.
                 */
                'revenue' => (int) $scoped()->revenueBearing()->sum('grand_total'),
                'refunded' => (int) $scoped()->sum('refunded_total'),

                'by_status' => $scoped()
                    ->selectRaw('status, COUNT(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status'),

                'by_payment_status' => $scoped()
                    ->selectRaw('payment_status, COUNT(*) as total')
                    ->groupBy('payment_status')
                    ->pluck('total', 'payment_status'),

                // The queue that needs attention: placed but not yet moving.
                'awaiting_action' => Order::query()
                    ->withStatus([OrderStatus::Pending, OrderStatus::Confirmed])
                    ->count(),
            ],
            message: 'Order statistics retrieved.',
        );
    }

    /**
     * Parse a comma-separated filter into valid enum values.
     *
     * Unknown values are dropped rather than rejected: a stale bookmark or a
     * renamed status should return a sensible list, not a 422.
     *
     * @param  class-string<\BackedEnum>  $enum
     * @return array<int, string>
     */
    private function enumList(?string $value, string $enum): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $candidate): ?string => $enum::tryFrom(trim($candidate))?->value,
            explode(',', $value),
        )));
    }

    /**
     * The admin client's idempotency key, validated for shape.
     *
     * Stops a double-clicked refund button paying out twice.
     */
    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '') {
            return null;
        }

        return preg_match('/^[A-Za-z0-9._\-]{8,64}$/', $key) === 1 ? $key : null;
    }
}
