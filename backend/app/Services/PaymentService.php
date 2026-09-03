<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\PermissionType;
use App\Models\Admin;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Notifications\AdminFailedPaymentNotification;
use App\Notifications\AdminPaymentReceivedNotification;
use App\Notifications\PaymentFailedNotification;
use App\Notifications\PaymentSuccessfulNotification;
use App\Payments\Contracts\PaymentGatewayInterface;
use App\Payments\Data\PaymentIntent;
use App\Payments\Data\PaymentVerification;
use App\Payments\Data\WebhookEvent;
use App\Payments\Exceptions\PaymentException;
use App\Payments\Exceptions\WebhookVerificationException;
use App\Payments\PaymentGatewayManager;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Orchestrates the payment lifecycle.
 *
 * ## The rule this class exists to enforce
 *
 * **A payment is marked successful only by a server-to-server verification.**
 *
 * Every path that could settle money — the customer's browser returning from a
 * hosted page, a webhook arriving from the open internet, an admin clicking
 * re-check, a reconciliation sweep — converges on {@see settle()}, and
 * `settle()` accepts only a {@see PaymentVerification}. A verification is
 * constructible only by a gateway's `verify()`, which is defined to call the
 * processor using credentials the customer does not hold.
 *
 * There is deliberately no method here that takes a status from a request. A
 * developer who wanted to trust a browser's `status=SUCCESS` would have to add
 * one, which is a visible change in a file about money rather than a quiet
 * omission somewhere else.
 *
 * ## The flow, and where each step lives
 *
 *   1. Create pending order ............ OrderService::placeFromSession
 *   2. Create payment transaction ...... OrderService::attachPayment
 *   3. Initiate payment ................ {@see initiate()}
 *   4. Redirect to gateway ............. the storefront, using the returned URL
 *   5. Handle success .................. {@see handleCallback()} -> verify -> settle
 *   6. Handle failure .................. {@see handleCallback()} -> verify -> settle
 *   7. Handle cancellation ............. {@see handleCallback()} -> verify -> settle
 *   8. Verify server-side .............. gateway->verify(), always
 *   9. Update payment .................. {@see settle()}, inside a transaction
 *  10. Update order .................... {@see settle()}, same transaction
 *  11. Finalize stock ................. {@see settle()}, same transaction
 *
 * Steps 9 to 11 share one transaction because they are one fact. A payment
 * marked paid whose order stayed pending, or whose stock was never released, is
 * a half-completed sale that reconciles against nothing.
 *
 * ## Duplicate deliveries
 *
 * Gateways retry. A customer refreshes the return page. Both produce the same
 * notification twice, and both are ordinary rather than exceptional. Two things
 * make that harmless:
 *
 * - The unique index on `payment_webhook_events (gateway, event_id)`, which
 *   makes a second delivery fail at the database rather than at a check two
 *   concurrent requests could interleave past.
 * - {@see settle()} short-circuiting on an already-settled payment, so even a
 *   notification that gets past the first guard changes nothing.
 */
final class PaymentService
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly OrderService $orders,
        private readonly StockReservationService $reservations,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Step 3 — initiate
    |--------------------------------------------------------------------------
    */

    /**
     * Start a payment for an order and record what the gateway said.
     *
     * The order and its pending payment row already exist by the time this
     * runs — OrderService creates both when the order is placed. This method
     * takes that payment to the processor.
     *
     * @throws PaymentException
     */
    public function initiate(Order $order, ?Payment $payment = null): PaymentIntent
    {
        $payment ??= $this->pendingPaymentFor($order);

        $gateway = $this->gateways->availableGateway($this->gatewayIdentifierFor($order, $payment));

        $intent = $gateway->initiate($order, $payment, $this->callbackContext($order, $payment, $gateway));

        /*
         * Recorded before the customer is redirected, never after.
         *
         * The reference the gateway issued is what a later callback or webhook
         * is matched on. If the browser navigated first and this write happened
         * on return, a fast gateway's webhook could arrive before the reference
         * was stored — and find no payment to attach itself to.
         */
        $this->recordInitiation($payment, $intent);

        if ($intent->isFailed) {
            $this->markFailed(
                $payment,
                $intent->failureReason ?? 'The payment could not be started.',
                $intent->raw,
            );
        }

        return $intent;
    }

    /**
     * Persist what initiation produced.
     */
    private function recordInitiation(Payment $payment, PaymentIntent $intent): void
    {
        $payment->forceFill([
            'gateway' => $intent->gateway,
            'transaction_reference' => $intent->reference ?? $payment->transaction_reference,
            'redirect_url' => $intent->redirectUrl,
            'initiated_at' => now(),
            /*
             * An offline gateway stays Pending — cash on delivery is arranged,
             * not paid. A redirect gateway moves to Processing, which is what
             * the reconciliation sweep looks for.
             */
            'status' => $intent->isCompleted ? Payment::STATUS_PENDING : Payment::STATUS_PROCESSING,
            'gateway_response' => $this->mergeResponse($payment, $intent->raw),
        ])->save();
    }

    /*
    |--------------------------------------------------------------------------
    | Steps 5-7 — the customer returns
    |--------------------------------------------------------------------------
    */

    /**
     * Handle a browser returning from a gateway.
     *
     * **The request is untrusted.** It travelled through the customer's
     * machine, where every field in it can be edited. It is used only to
     * identify which transaction is being reported; the gateway is then asked
     * what actually happened, and that answer is what settles anything.
     *
     * `$outcome` is the route the customer came back on. It decides which page
     * they see, not whether they paid — a shopper can land on the success URL
     * with an unpaid order, either by editing the address or by a gateway
     * redirecting optimistically.
     */
    public function handleCallback(
        Request $request,
        Payment $payment,
        string $outcome,
    ): PaymentVerification {
        $gateway = $this->gateways->gateway($payment->gateway ?? $this->gateways->defaultIdentifier());

        $event = $this->recordEvent(
            gateway: $gateway->identifier(),
            source: PaymentWebhookEvent::SOURCE_CALLBACK,
            eventId: $this->callbackEventId($payment, $outcome, $request),
            eventType: 'callback.'.$outcome,
            reference: $payment->transaction_reference,
            payment: $payment,
            payload: $request->all(),
            isVerified: true,
            ipAddress: $request->ip(),
        );

        /*
         * A duplicate delivery. The customer refreshed, or the gateway sent the
         * same redirect twice.
         *
         * Answered with the payment's current state rather than an error: from
         * the shopper's side this is the same page they were already looking
         * at, and an error would be alarming and wrong.
         */
        if ($event === null) {
            return $this->verificationFromPayment($payment, $gateway);
        }

        try {
            $verification = $gateway->handleCallback($request, $payment, $outcome);
        } catch (PaymentException $exception) {
            $event->markRejected($exception->getMessage());

            Log::warning('Payment callback could not be verified with the gateway.', array_merge(
                $exception->context(),
                ['payment' => $payment->uuid, 'outcome' => $outcome],
            ));

            throw $exception;
        }

        $this->settle($payment, $verification);

        $event->markProcessed();

        return $verification;
    }

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    */

    /**
     * Handle an inbound webhook.
     *
     * Three defences, each closing a different hole:
     *
     * 1. **Signature.** `parseWebhook()` throws unless the payload is provably
     *    from the gateway. An unsigned webhook is an anonymous instruction
     *    about money.
     * 2. **Replay.** The unique index on the events table means a redelivered
     *    event is recorded once and acted on once.
     * 3. **Re-verification.** Even a correctly signed event does not settle
     *    anything by itself — the reference is taken out of it and the gateway
     *    is asked directly. The signature proves origin; the lookup proves the
     *    amount.
     *
     * @return array{handled: bool, reason?: string}
     *
     * @throws WebhookVerificationException when the signature does not verify.
     */
    public function handleWebhook(Request $request, string $gatewayIdentifier): array
    {
        $gateway = $this->gateways->gateway($gatewayIdentifier);

        try {
            $event = $gateway->parseWebhook($request);
        } catch (WebhookVerificationException $exception) {
            /*
             * Recorded even though it is rejected — especially because it is
             * rejected. One unverified webhook is noise; a run of them is
             * someone probing the endpoint, and that is only visible if the
             * attempts are stored.
             */
            $this->recordEvent(
                gateway: $gatewayIdentifier,
                source: PaymentWebhookEvent::SOURCE_WEBHOOK,
                eventId: 'rejected:'.hash('sha256', $request->getContent().microtime()),
                eventType: 'signature.rejected',
                reference: null,
                payment: null,
                payload: ['error' => $exception->getMessage()],
                isVerified: false,
                ipAddress: $request->ip(),
            )?->markRejected($exception->getMessage());

            Log::warning('Rejected an unverifiable payment webhook.', array_merge(
                $exception->context(),
                ['ip' => $request->ip()],
            ));

            throw $exception;
        }

        $payment = $this->resolvePaymentFor($event);

        $record = $this->recordEvent(
            gateway: $gateway->identifier(),
            source: PaymentWebhookEvent::SOURCE_WEBHOOK,
            eventId: $this->webhookEventId($event),
            eventType: $event->type,
            reference: $event->reference,
            payment: $payment,
            payload: $event->raw,
            isVerified: true,
            ipAddress: $request->ip(),
        );

        // The unique index fired: this event has already been handled.
        if ($record === null) {
            return ['handled' => true, 'reason' => 'duplicate'];
        }

        if ($event->isIgnorable()) {
            /*
             * Acknowledged rather than errored. Gateways send far more event
             * types than a store acts on, and answering with an error for one
             * we do not need would put the endpoint into a permanent retry loop
             * and eventually get the webhook disabled.
             */
            $record->markRejected('Event type is not handled by this application.');

            return ['handled' => true, 'reason' => 'unhandled_type'];
        }

        if ($payment === null) {
            $record->markRejected('No payment matches this event.');

            Log::warning('Webhook did not match any payment.', [
                'gateway' => $gateway->identifier(),
                'reference' => $event->reference,
            ]);

            return ['handled' => true, 'reason' => 'unmatched'];
        }

        /*
         * Re-verified against the gateway. See the method docblock: a signature
         * proves who sent the message, not that the message describes this
         * order at this amount.
         */
        $verification = $gateway->verify($payment);

        $this->settle($payment, $verification);

        $record->markProcessed();

        return ['handled' => true];
    }

    /*
    |--------------------------------------------------------------------------
    | Step 8 — verification
    |--------------------------------------------------------------------------
    */

    /**
     * Ask the gateway what happened, and act on the answer.
     *
     * The entry point for an admin re-checking a payment and for the
     * reconciliation sweep. Safe to call repeatedly — every gateway's
     * `verify()` is required to be side-effect free.
     */
    public function verifyAndSettle(Payment $payment): PaymentVerification
    {
        $gateway = $this->gateways->gateway($payment->gateway ?? $this->gateways->defaultIdentifier());

        $verification = $gateway->verify($payment);

        $this->settle($payment, $verification);

        return $verification;
    }

    /*
    |--------------------------------------------------------------------------
    | Steps 9-11 — settle
    |--------------------------------------------------------------------------
    */

    /**
     * Apply a verified outcome to the payment, the order, and stock.
     *
     * **The only method that may mark money as received**, and it accepts only
     * a {@see PaymentVerification} — an object no request can construct.
     *
     * One transaction covers all three, because they are one fact. A payment
     * marked paid whose order stayed pending is a sale that reconciles against
     * nothing, and a shopper looking at an unconfirmed order they have been
     * charged for.
     */
    public function settle(Payment $payment, PaymentVerification $verification): Payment
    {
        $before = $payment->fresh()?->status ?? $payment->status;

        $settled = $this->settleWithinTransaction($payment, $verification);

        /*
         * Notified only after the transaction above has committed, and only on
         * a genuine transition — a duplicate delivery re-entering this method
         * for an already-settled payment must not send a second "payment
         * received" email. The same rule InventoryService and OrderService
         * follow for their own events: a listener that emails a customer must
         * never fire for a change that could still roll back, or for a change
         * that did not actually happen this time.
         */
        if ($before !== $settled->status) {
            $this->announceSettlement($settled);
        }

        return $settled;
    }

    private function settleWithinTransaction(Payment $payment, PaymentVerification $verification): Payment
    {
        return DB::transaction(function () use ($payment, $verification): Payment {
            /*
             * Re-read under a row lock.
             *
             * A callback and a webhook for the same payment routinely arrive
             * within milliseconds of each other. Without the lock both would
             * read Processing, both would settle, and the order would receive
             * two confirmations and two history rows.
             */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            $locked->forceFill([
                'attempt_count' => (int) $locked->attempt_count + 1,
                'verified_at' => now(),
                'gateway_response' => $this->mergeResponse($locked, $verification->raw),
            ])->save();

            /*
             * Already settled. A duplicate notification, or the second of two
             * racing ones.
             *
             * Returned unchanged rather than re-applied: settling twice would
             * write a second payment-status history row and re-run the order
             * transition, and for a paid order it would re-confirm something
             * already confirmed.
             */
            if ($locked->isSettled()) {
                return $locked;
            }

            if ($verification->isPending()) {
                // Nothing to do yet. The sweep will ask again.
                return $locked;
            }

            if ($verification->isPaid()) {
                $this->applyPaid($locked, $verification);

                return $locked->refresh();
            }

            if ($verification->isCancelled()) {
                $this->applyCancelled($locked, $verification);

                return $locked->refresh();
            }

            $this->applyFailed($locked, $verification);

            return $locked->refresh();
        });
    }

    /**
     * Send the customer and admin notifications a settlement implies.
     *
     * Called only after {@see settleWithinTransaction()}'s transaction has
     * committed, and only when the payment's status genuinely changed — see
     * the caller. `loadMissing` guards the order relation for the same reason
     * every notification class documents: a queued job's model has no
     * relations pre-loaded, and strict mode would throw on an unloaded
     * belongsTo otherwise.
     */
    private function announceSettlement(Payment $payment): void
    {
        $payment->loadMissing('order');
        $order = $payment->order;

        if ($order === null) {
            return;
        }

        if ($payment->status === Payment::STATUS_PAID) {
            $this->notifyCustomer($order, new PaymentSuccessfulNotification($order, $payment));

            foreach ($this->adminsToNotify(PermissionType::ViewPayments) as $admin) {
                $admin->notify(new AdminPaymentReceivedNotification($order, $payment));
            }

            return;
        }

        if ($payment->status === Payment::STATUS_FAILED) {
            $this->notifyCustomer($order, new PaymentFailedNotification($order, $payment));

            foreach ($this->adminsToNotify(PermissionType::ViewPayments) as $admin) {
                $admin->notify(new AdminFailedPaymentNotification($order, $payment));
            }
        }
    }

    /**
     * Send to the customer's account when one exists, or to their email
     * directly for a guest order. Mirrors SendOrderNotifications::notifyCustomer
     * — kept as a second small copy rather than a shared dependency, since
     * pulling a listener class into a service for one helper method would
     * invert which of the two owns the relationship.
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

    /**
     * The paid path: steps 9, 10, and 11.
     *
     * @throws PaymentException when the gateway's amount disagrees with the order.
     */
    private function applyPaid(Payment $payment, PaymentVerification $verification): void
    {
        $order = $payment->order()->lockForUpdate()->firstOrFail();

        /*
         * The amount check.
         *
         * Without it, a callback pointing at the wrong transaction — or a
         * deliberately substituted one — could settle a large order with a
         * small payment. The gateway's own figure is compared against the
         * order's total, and a mismatch is refused rather than reconciled.
         */
        if ((bool) config('payment.verification.require_amount_match', true)) {
            $tolerance = (int) config('payment.verification.amount_tolerance', 0);

            if (! $verification->matchesAmount((int) $order->grand_total, $tolerance)) {
                $reason = sprintf(
                    'Amount mismatch: the gateway reported %s but the order total is %d.',
                    $verification->amount === null ? 'no amount' : (string) $verification->amount,
                    (int) $order->grand_total,
                );

                $this->applyFailed(
                    $payment,
                    PaymentVerification::failed(
                        gateway: $verification->gateway,
                        reason: $reason,
                        reference: $verification->reference,
                        raw: $verification->raw,
                    ),
                );

                Log::critical('A verified payment did not match its order total.', [
                    'payment' => $payment->uuid,
                    'order' => $order->order_number,
                    'reported' => $verification->amount,
                    'expected' => (int) $order->grand_total,
                ]);

                return;
            }
        }

        // Step 9 — the payment row.
        $payment->forceFill([
            'status' => Payment::STATUS_PAID,
            'transaction_reference' => $verification->reference ?? $payment->transaction_reference,
            'card_brand' => $verification->cardBrand ?? $payment->card_brand,
            'card_last_four' => $verification->cardLastFour ?? $payment->card_last_four,
            'paid_at' => now(),
            'failure_reason' => null,
        ])->save();

        // Step 10 — the order. OrderService owns the transition, so the audit
        // row and the confirmation happen with it.
        $this->orders->markPaid(
            order: $order,
            actor: null,
            comment: sprintf('Payment confirmed by %s.', $verification->gateway),
        );

        // Step 11 — stock.
        $this->finaliseStock($order);
    }

    /**
     * Step 11 — release the holds the checkout was carrying.
     *
     * Stock was already decremented at placement, under InventoryService's row
     * locks. What remains is the *reservation*, which exists to stop another
     * shopper taking the units while this one was paying. Once the money has
     * arrived that hold has done its job and is committed.
     *
     * Failing here must not fail the payment: the customer has been charged and
     * the order is confirmed. A stranded reservation expires on its own within
     * minutes, which is a far smaller problem than an exception thrown after
     * money moved.
     */
    private function finaliseStock(Order $order): void
    {
        try {
            $session = CheckoutSession::query()
                ->where('order_id', $order->getKey())
                ->first();

            if ($session !== null) {
                $this->reservations->commitForSession($session, $order);
            }
        } catch (\Throwable $exception) {
            Log::error('Could not finalise stock reservations after payment.', [
                'order' => $order->order_number,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The failed path.
     *
     * The order is *not* cancelled. A declined card is very often followed by a
     * successful retry on another one, and cancelling would return the stock,
     * release the order, and leave the shopper unable to complete a purchase
     * they are actively trying to make. The order stays Pending; an admin or a
     * scheduled sweep decides when to give up on it.
     */
    private function applyFailed(Payment $payment, PaymentVerification $verification): void
    {
        $payment->forceFill([
            'status' => Payment::STATUS_FAILED,
            'transaction_reference' => $verification->reference ?? $payment->transaction_reference,
            'failure_reason' => mb_substr(
                $verification->failureReason ?? 'The payment was not completed.',
                0,
                512,
            ),
            'failed_at' => now(),
        ])->save();

        $order = $payment->order()->first();

        if ($order !== null && $order->payment_status !== PaymentStatus::Failed) {
            $this->orders->setPaymentStatus(
                $order,
                PaymentStatus::Failed,
                null,
                $verification->failureReason ?? 'The payment was not completed.',
            );
        }
    }

    /**
     * The cancelled path.
     *
     * Recorded distinctly from a failure so a store can tell "the processor
     * said no" from "the shopper changed their mind" — chasing the second the
     * way you chase the first means nagging people who decided not to buy.
     *
     * The order's payment status stays Pending: the customer may come straight
     * back and pay by another method.
     */
    private function applyCancelled(Payment $payment, PaymentVerification $verification): void
    {
        $payment->forceFill([
            'status' => Payment::STATUS_CANCELLED,
            'transaction_reference' => $verification->reference ?? $payment->transaction_reference,
            'failure_reason' => 'The payment was cancelled before it completed.',
            'cancelled_at' => now(),
        ])->save();
    }

    /**
     * Mark a payment failed without a gateway verification.
     *
     * Used when initiation itself failed — the processor refused before the
     * customer ever saw a page, so there is no transaction to verify.
     *
     * @param  array<string, mixed>  $raw
     */
    private function markFailed(Payment $payment, string $reason, array $raw = []): void
    {
        $payment->forceFill([
            'status' => Payment::STATUS_FAILED,
            'failure_reason' => mb_substr($reason, 0, 512),
            'failed_at' => now(),
            'gateway_response' => $this->mergeResponse($payment, $raw),
        ])->save();
    }

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    */

    /**
     * Re-check payments the customer never returned from.
     *
     * The safety net for the case that costs a store real money: the customer
     * paid, and then closed the tab before the redirect fired. The webhook
     * usually covers it — but not every gateway sends one, and webhooks are
     * lost. Without this, that order sits unpaid forever while the money is in
     * the store's account.
     *
     * @return array{checked: int, settled: int}
     */
    public function reconcilePending(int $olderThanMinutes = 15, int $limit = 100): array
    {
        $payments = Payment::query()
            ->awaitingReconciliation($olderThanMinutes)
            ->with('order')
            ->limit($limit)
            ->get();

        $settled = 0;

        foreach ($payments as $payment) {
            try {
                $verification = $this->verifyAndSettle($payment);

                if ($verification->isSettled()) {
                    $settled++;
                }
            } catch (\Throwable $exception) {
                /*
                 * One unreachable gateway must not stop the sweep. The rest of
                 * the batch is other customers' money.
                 */
                Log::warning('Could not reconcile a pending payment.', [
                    'payment' => $payment->uuid,
                    'gateway' => $payment->gateway,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return ['checked' => $payments->count(), 'settled' => $settled];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * The payment row a new attempt should use.
     *
     * Reuses an unsettled row rather than creating one per attempt, so a
     * customer who reloads the payment page does not accumulate a trail of
     * abandoned rows against one order.
     */
    public function pendingPaymentFor(Order $order): Payment
    {
        $existing = $order->payments()
            ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING])
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Payment::query()->create([
            'order_id' => $order->getKey(),
            'method' => $order->payment_method,
            'status' => Payment::STATUS_PENDING,
            'amount' => (int) $order->grand_total,
            'currency' => $order->currency,
        ]);
    }

    /**
     * Which gateway an order should be processed through.
     *
     * The payment's own gateway wins when it has one — a payment already
     * initiated must be verified against the processor that issued its
     * reference, not against whatever the order's method maps to now.
     */
    private function gatewayIdentifierFor(Order $order, Payment $payment): string
    {
        if (is_string($payment->gateway) && $payment->gateway !== '') {
            return $payment->gateway;
        }

        return $this->gatewayForMethod($order->payment_method->value);
    }

    /**
     * Map a checkout payment method onto a gateway identifier.
     *
     * The one place the two vocabularies meet. A method is what a shopper
     * chooses; a gateway is what processes it, and the mapping is
     * configuration rather than a hardcoded match — a store switching its card
     * processor from Stripe to SSLCommerz changes a config value.
     */
    public function gatewayForMethod(string $method): string
    {
        $map = (array) config('payment.method_gateways', []);

        if (isset($map[$method]) && is_string($map[$method])) {
            return $map[$method];
        }

        // A method whose name is itself a registered gateway maps to it.
        if ($this->gateways->has($method)) {
            return $method;
        }

        return $this->gateways->defaultIdentifier();
    }

    /**
     * The URLs a gateway needs to send the customer back to.
     *
     * @return array<string, mixed>
     */
    private function callbackContext(Order $order, Payment $payment, PaymentGatewayInterface $gateway): array
    {
        return [
            'success_url' => route('api.v1.payments.callback', [
                'gateway' => $gateway->identifier(),
                'payment' => $payment->uuid,
                'outcome' => 'success',
            ]),
            'failure_url' => route('api.v1.payments.callback', [
                'gateway' => $gateway->identifier(),
                'payment' => $payment->uuid,
                'outcome' => 'failure',
            ]),
            'cancel_url' => route('api.v1.payments.callback', [
                'gateway' => $gateway->identifier(),
                'payment' => $payment->uuid,
                'outcome' => 'cancel',
            ]),
            'callback_url' => route('api.v1.payments.callback', [
                'gateway' => $gateway->identifier(),
                'payment' => $payment->uuid,
                'outcome' => 'success',
            ]),
            'webhook_url' => route('api.v1.payments.webhook', [
                'gateway' => $gateway->identifier(),
            ]),
            'order' => $order,
        ];
    }

    /**
     * Record an inbound event, or null when it is a duplicate.
     *
     * The insert *is* the deduplication. A `SELECT` first would let two
     * concurrent deliveries both find nothing and both proceed; the unique
     * index cannot be raced.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordEvent(
        string $gateway,
        string $source,
        string $eventId,
        string $eventType,
        ?string $reference,
        ?Payment $payment,
        array $payload,
        bool $isVerified,
        ?string $ipAddress,
    ): ?PaymentWebhookEvent {
        try {
            return PaymentWebhookEvent::query()->create([
                'gateway' => $gateway,
                'source' => $source,
                'event_id' => $eventId,
                'event_type' => $eventType,
                'transaction_reference' => $reference,
                'payment_id' => $payment?->getKey(),
                'order_id' => $payment?->order_id,
                'is_verified' => $isVerified,
                'payload' => $payload,
                'ip_address' => $ipAddress,
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * A dedupe key for a browser callback.
     *
     * Gateways issue no event id for a redirect, so one is synthesised. It
     * includes the gateway's own transaction identifier where the request
     * carries one — a customer refreshing the page produces an identical key
     * and is deduplicated, while a genuinely new attempt carries a new
     * reference and is not.
     */
    private function callbackEventId(Payment $payment, string $outcome, Request $request): string
    {
        /*
         * Only the request's OWN identifiers, never the payment's stored one.
         *
         * The stored reference is not stable across a settlement: settling
         * overwrites `transaction_reference` with whatever the gateway
         * ultimately reported, so a key built from it changes the moment the
         * first callback succeeds — and the second delivery of that same
         * callback computes a different key, misses the unique index, and is
         * processed again. Every gateway that issues a final reference
         * distinct from its session id would get one free duplicate.
         *
         * The payment uuid plus the outcome is stable and already unique per
         * attempt. The request's reference is appended only when it is present,
         * so that a genuinely NEW attempt — a retry carrying a fresh session id
         * — is still distinguishable from a refresh of the old one.
         */
        $reference = $request->input('val_id')
            ?? $request->input('session_id')
            ?? $request->input('paymentID');

        return sprintf(
            'callback:%s:%s:%s',
            $payment->uuid,
            $outcome,
            is_string($reference) && $reference !== '' ? $reference : 'none',
        );
    }

    /**
     * A dedupe key for a webhook.
     *
     * Prefers the gateway's own event id, which is what its retries reuse.
     * Falls back to a hash of the type and reference for processors that issue
     * none — weaker, but still stops the common redelivery.
     */
    private function webhookEventId(WebhookEvent $event): string
    {
        if ($event->eventId !== null && $event->eventId !== '') {
            return $event->eventId;
        }

        return 'derived:'.hash('sha256', implode('|', [
            $event->gateway,
            $event->type,
            $event->reference ?? '',
            (string) ($event->amount ?? ''),
        ]));
    }

    /**
     * Find the payment a webhook refers to.
     *
     * Two routes in, because gateways differ in what they echo back. Our own
     * reference is preferred — it is a uuid we generated, so a match is
     * unambiguous — with the gateway's transaction id as the fallback.
     */
    private function resolvePaymentFor(WebhookEvent $event): ?Payment
    {
        if ($event->orderReference !== null && $event->orderReference !== '') {
            $payment = Payment::query()->where('uuid', $event->orderReference)->first();

            if ($payment !== null) {
                return $payment;
            }
        }

        if ($event->reference !== null && $event->reference !== '') {
            return Payment::query()
                ->byReference($event->gateway, $event->reference)
                ->first();
        }

        return null;
    }

    /**
     * A verification describing a payment's already-known state.
     *
     * Used to answer a duplicate callback without troubling the gateway again.
     */
    private function verificationFromPayment(Payment $payment, PaymentGatewayInterface $gateway): PaymentVerification
    {
        return match ($payment->status) {
            Payment::STATUS_PAID => PaymentVerification::paid(
                gateway: $gateway->identifier(),
                reference: (string) ($payment->transaction_reference ?? $payment->uuid),
                amount: (int) $payment->amount,
                currency: $payment->currency,
                raw: ['note' => 'Already settled; duplicate notification.'],
            ),
            Payment::STATUS_CANCELLED => PaymentVerification::cancelled(
                gateway: $gateway->identifier(),
                reference: $payment->transaction_reference,
            ),
            Payment::STATUS_FAILED => PaymentVerification::failed(
                gateway: $gateway->identifier(),
                reason: $payment->failure_reason ?? 'The payment was not completed.',
                reference: $payment->transaction_reference,
            ),
            default => PaymentVerification::pending(
                gateway: $gateway->identifier(),
                reference: $payment->transaction_reference,
            ),
        };
    }

    /**
     * Merge a new gateway response into what is already stored.
     *
     * Kept rather than overwritten: a payment's history is create, then
     * callback, then webhook, and each carries fields the others do not —
     * SSLCommerz's `val_id` arrives on the callback and is needed for every
     * later verification.
     *
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeResponse(Payment $payment, array $incoming): array
    {
        if ($incoming === []) {
            return (array) ($payment->gateway_response ?? []);
        }

        return array_merge((array) ($payment->gateway_response ?? []), $incoming);
    }

    /**
     * Whether a query exception is a unique-constraint violation.
     *
     * By SQLSTATE rather than message text, which differs between MySQL and the
     * SQLite the test suite runs on.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23000' || $exception->getCode() === '23505';
    }
}
