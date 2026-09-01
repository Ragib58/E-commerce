<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Http\Resources\Api\V1\PaymentWebhookEventResource;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\Refund;
use App\Payments\Exceptions\PaymentException;
use App\Payments\PaymentGatewayManager;
use App\Services\PaymentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Payment administration.
 *
 * Gated on `view_payments` for reads and `manage_payments` for the two actions
 * that reach a processor. That split matters: reading transactions is what a
 * support agent does all day, while re-verifying against a gateway makes an
 * outbound API call, and an accounts clerk browsing a list should not be able
 * to generate traffic against a rate-limited processor.
 *
 * Every filter is applied in SQL. Loading payments and filtering a collection
 * would make the pagination counts lie about how many matches exist — a bug
 * that only shows up on page two, by which point the totals have been quoted to
 * someone.
 */
final class PaymentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentGatewayManager $gateways,
    ) {}

    /**
     * GET /admin/payments
     *
     * All transactions, filterable by gateway, status, date range, and order.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:191'],
            'gateway' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'max:128'],
            'order' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'sort' => ['nullable', 'string', 'in:newest,oldest,amount_high,amount_low'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Payment::query()->with(['order']);

        /*
         * Status accepts a comma-separated list so "everything unresolved" —
         * pending plus processing — is one view rather than two requests.
         * Unknown values are dropped rather than rejected: a stale bookmark
         * should return a sensible list, not a 422.
         */
        if (($statuses = $this->statusList($validated['status'] ?? null)) !== []) {
            $query->whereIn('status', $statuses);
        }

        if (($gateway = $validated['gateway'] ?? null) !== null && $gateway !== '') {
            $query->where('gateway', $gateway);
        }

        /*
         * Filtering by order accepts the order number, which is what an admin
         * actually has in front of them. A join rather than a subquery so the
         * index on `orders.order_number` is used.
         */
        if (($order = $validated['order'] ?? null) !== null && $order !== '') {
            $query->whereHas('order', fn ($inner) => $inner->where('order_number', $order));
        }

        $query->when(
            isset($validated['from']),
            fn ($q) => $q->where('created_at', '>=', Carbon::parse($validated['from'])->startOfDay()),
        );

        $query->when(
            isset($validated['to']),
            fn ($q) => $q->where('created_at', '<=', Carbon::parse($validated['to'])->endOfDay()),
        );

        /*
         * Free-text search across what a support agent types: the gateway's
         * transaction reference, our payment uuid, or the order number.
         *
         * The term is escaped so an address or reference containing `%` does
         * not turn the lookup into a full-table wildcard scan.
         */
        if (($search = trim((string) ($validated['search'] ?? ''))) !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);

            $query->where(function ($inner) use ($escaped): void {
                $inner
                    ->where('transaction_reference', 'like', "%{$escaped}%")
                    ->orWhere('uuid', 'like', "%{$escaped}%")
                    ->orWhereHas('order', fn ($o) => $o
                        ->where('order_number', 'like', "%{$escaped}%")
                        ->orWhere('customer_email', 'like', "%{$escaped}%"));
            });
        }

        match ($validated['sort'] ?? 'newest') {
            'oldest' => $query->oldest('id'),
            'amount_high' => $query->orderByDesc('amount'),
            'amount_low' => $query->orderBy('amount'),
            default => $query->latest('id'),
        };

        $payments = $query->paginate((int) ($validated['per_page'] ?? 25));

        return $this->successResponse(
            data: PaymentResource::collection($payments),
            message: 'Payments retrieved.',
            meta: [
                /*
                 * The filter vocabulary travels with the list, so the panel's
                 * dropdowns are built from what the application actually
                 * supports rather than hardcoded in the frontend — the same
                 * "fully dynamic" rule the rest of this project follows.
                 */
                'filters' => [
                    'statuses' => $this->statusOptions(),
                    'gateways' => array_map(
                        fn ($gateway): array => [
                            'value' => $gateway->identifier(),
                            'label' => $gateway->displayName(),
                            'is_available' => $gateway->isAvailable(),
                        ],
                        $this->gateways->all(),
                    ),
                ],
            ],
        );
    }

    /**
     * GET /admin/payments/statistics
     *
     * Headline figures, broken down the way the brief asks the panel to show
     * them: successful, failed, pending, and refunded.
     */
    public function statistics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'gateway' => ['nullable', 'string', 'max:64'],
        ]);

        $scoped = function () use ($validated) {
            return Payment::query()
                ->when(
                    isset($validated['from']),
                    fn ($q) => $q->where('created_at', '>=', Carbon::parse($validated['from'])->startOfDay()),
                )
                ->when(
                    isset($validated['to']),
                    fn ($q) => $q->where('created_at', '<=', Carbon::parse($validated['to'])->endOfDay()),
                )
                ->when(
                    ! empty($validated['gateway']),
                    fn ($q) => $q->where('gateway', $validated['gateway']),
                );
        };

        return $this->successResponse(
            data: [
                'total_transactions' => $scoped()->count(),

                /*
                 * Captured, not merely attempted. Summing every row regardless
                 * of status would report failed attempts as revenue, which is
                 * the single most misleading figure a payments dashboard can
                 * show.
                 */
                'captured' => (int) $scoped()->where('status', Payment::STATUS_PAID)->sum('amount'),

                'by_status' => $scoped()
                    ->selectRaw('status, COUNT(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status'),

                'by_gateway' => $scoped()
                    ->selectRaw('gateway, COUNT(*) as total, SUM(amount) as captured')
                    ->where('status', Payment::STATUS_PAID)
                    ->groupBy('gateway')
                    ->get()
                    ->keyBy('gateway')
                    ->map(fn ($row): array => [
                        'transactions' => (int) $row->total,
                        'captured' => (int) $row->captured,
                    ]),

                // Refunds live on their own table; the payments view reports the
                // count so the two can be reconciled without leaving the page.
                'refunded_total' => (int) Refund::query()
                    ->completed()
                    ->when(
                        isset($validated['from']),
                        fn ($q) => $q->where('created_at', '>=', Carbon::parse($validated['from'])->startOfDay()),
                    )
                    ->when(
                        isset($validated['to']),
                        fn ($q) => $q->where('created_at', '<=', Carbon::parse($validated['to'])->endOfDay()),
                    )
                    ->sum('amount'),

                /*
                 * Payments the customer never returned from. The queue worth
                 * acting on: money may have moved without anyone recording it.
                 */
                'awaiting_reconciliation' => Payment::query()->awaitingReconciliation()->count(),
            ],
            message: 'Payment statistics retrieved.',
        );
    }

    /**
     * GET /admin/payments/{payment}
     */
    public function show(string $payment): JsonResponse
    {
        $record = Payment::query()
            ->where('uuid', $payment)
            ->with(['order.items'])
            ->first();

        if ($record === null) {
            return $this->errorResponse(
                message: 'That payment could not be found.',
                status: 404,
                code: 'PAYMENT_NOT_FOUND',
            );
        }

        return $this->successResponse(
            data: new PaymentResource($record),
            message: 'Payment retrieved.',
        );
    }

    /**
     * GET /admin/payments/{payment}/events
     *
     * Every callback and webhook received for this payment.
     *
     * The forensic view. When a customer says they paid and the order says
     * otherwise, this is the record that settles it — including the deliveries
     * that were rejected, which are often the explanation.
     */
    public function events(string $payment): JsonResponse
    {
        $record = Payment::query()->where('uuid', $payment)->first();

        if ($record === null) {
            return $this->errorResponse(
                message: 'That payment could not be found.',
                status: 404,
                code: 'PAYMENT_NOT_FOUND',
            );
        }

        $events = PaymentWebhookEvent::query()
            ->where('payment_id', $record->getKey())
            ->latest('id')
            ->limit(100)
            ->get();

        return $this->successResponse(
            data: PaymentWebhookEventResource::collection($events),
            message: 'Payment events retrieved.',
        );
    }

    /**
     * POST /admin/payments/{payment}/verify
     *
     * Re-ask the gateway what happened, and apply the answer.
     *
     * The manual counterpart to the reconciliation sweep, for the case where a
     * customer insists they paid. It goes through exactly the same settlement
     * path as a callback or a webhook — an admin cannot mark a payment paid,
     * only ask the gateway to.
     *
     * That is deliberate. An endpoint that let staff set a payment to Paid
     * would be the one hole in the "never without verification" rule, and it
     * would be used, because it is the quickest way to close a support ticket.
     */
    public function verify(string $payment): JsonResponse
    {
        $record = Payment::query()->where('uuid', $payment)->with('order')->first();

        if ($record === null) {
            return $this->errorResponse(
                message: 'That payment could not be found.',
                status: 404,
                code: 'PAYMENT_NOT_FOUND',
            );
        }

        try {
            $verification = $this->payments->verifyAndSettle($record);
        } catch (PaymentException $exception) {
            return $this->errorResponse(
                message: $exception->customerMessage(),
                status: 502,
                code: 'VERIFICATION_FAILED',
            );
        }

        return $this->successResponse(
            data: [
                'verification' => [
                    'status' => $verification->status,
                    'reference' => $verification->reference,
                    'amount' => $verification->amount,
                    'currency' => $verification->currency,
                    'failure_reason' => $verification->failureReason,
                ],
                'payment' => new PaymentResource($record->refresh()),
            ],
            message: sprintf('The gateway reports this payment as %s.', $verification->status),
        );
    }

    /**
     * GET /admin/payments/events/unverified
     *
     * Inbound notifications whose signature did not verify.
     *
     * A security view rather than an operational one. One failure is noise —
     * a misconfigured secret, a gateway testing an endpoint. A run of them is
     * someone probing, and that pattern is only visible if the attempts are
     * stored and queryable.
     */
    public function unverifiedEvents(Request $request): JsonResponse
    {
        $events = PaymentWebhookEvent::query()
            ->unverified()
            ->latest('id')
            ->paginate((int) $request->integer('per_page', 25));

        return $this->successResponse(
            data: PaymentWebhookEventResource::collection($events),
            message: 'Unverified webhook attempts retrieved.',
        );
    }

    /**
     * Parse a comma-separated status filter into known values.
     *
     * @return array<int, string>
     */
    private function statusList(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $known = [
            Payment::STATUS_PENDING,
            Payment::STATUS_PROCESSING,
            Payment::STATUS_PAID,
            Payment::STATUS_FAILED,
            Payment::STATUS_CANCELLED,
        ];

        return array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            static fn (string $candidate): bool => in_array($candidate, $known, strict: true),
        ));
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return [
            ['value' => Payment::STATUS_PENDING, 'label' => 'Pending'],
            ['value' => Payment::STATUS_PROCESSING, 'label' => 'Awaiting customer'],
            ['value' => Payment::STATUS_PAID, 'label' => 'Successful'],
            ['value' => Payment::STATUS_FAILED, 'label' => 'Failed'],
            ['value' => Payment::STATUS_CANCELLED, 'label' => 'Cancelled'],
        ];
    }
}
