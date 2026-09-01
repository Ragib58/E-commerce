<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\Exceptions\PaymentException;
use App\Payments\Exceptions\WebhookVerificationException;
use App\Payments\PaymentGatewayManager;
use App\Services\PaymentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Payment initiation, gateway callbacks, and webhooks.
 *
 * ## Three surfaces with three different threat models
 *
 * **Initiation** is authenticated in the ordinary way — the caller must be able
 * to reach the order, which the policy decides.
 *
 * **Callbacks** cannot be. The request arrives as a browser redirect from a
 * third party, so it carries no bearer token and no session. The payment's uuid
 * in the URL is what identifies it, and that is *all* it does: the uuid is not
 * treated as a credential, because nothing in this handler trusts the request's
 * contents. Whatever the query string says, the gateway is asked directly what
 * happened.
 *
 * **Webhooks** cannot be authenticated conventionally either. They are
 * server-to-server posts from the gateway, authenticated by signature inside
 * the gateway implementation — which is why an unverifiable one is answered
 * with a 400 and nothing is written.
 *
 * ## Callbacks redirect; they do not return JSON
 *
 * A customer's browser lands here. It must end up on a page, so these endpoints
 * answer with a redirect to the storefront. Returning JSON would show a shopper
 * a raw document after paying.
 */
final class PaymentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentGatewayManager $gateways,
    ) {}

    /**
     * GET /payments/methods
     *
     * The gateways this store can currently process a payment through.
     *
     * Derived from the manager, so a gateway missing a credential is absent
     * rather than offered and then failing — the most expensive moment to
     * discover a configuration error is when a customer is trying to pay.
     */
    public function methods(): JsonResponse
    {
        $methods = array_map(
            fn ($gateway): array => [
                'gateway' => $gateway->identifier(),
                'label' => $gateway->displayName(),
                'is_offline' => $gateway->isOffline(),
                'supports_refunds' => $gateway->supportsRefunds(),
            ],
            $this->gateways->available(),
        );

        return $this->successResponse(
            data: $methods,
            message: 'Payment methods retrieved.',
        );
    }

    /**
     * POST /orders/{order}/pay
     *
     * Step 3 — start a payment and hand back where to send the customer.
     *
     * Returns the redirect URL rather than issuing the redirect itself. The
     * storefront is a separate origin and needs to decide how to navigate —
     * a full-page redirect, a new tab, or Stripe.js — and a 302 from an API
     * endpoint would take that decision away.
     */
    public function pay(Request $request, Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        // An order already paid must not start a second payment. Without this a
        // customer refreshing the payment page could be charged twice.
        if ($order->payment_status->isSettled()) {
            return $this->errorResponse(
                message: 'This order has already been paid.',
                status: 422,
                code: 'ALREADY_PAID',
            );
        }

        try {
            $intent = $this->payments->initiate($order);
        } catch (PaymentException $exception) {
            Log::warning('Payment initiation failed.', array_merge(
                $exception->context(),
                ['order' => $order->order_number],
            ));

            // The customer-safe message, never the diagnostic one — gateway
            // errors name internal endpoints and quote credential-shaped ids.
            return $this->errorResponse(
                message: $exception->customerMessage(),
                status: 502,
                code: 'PAYMENT_INITIATION_FAILED',
            );
        }

        if ($intent->isFailed) {
            return $this->errorResponse(
                message: $intent->failureReason ?? 'The payment could not be started.',
                status: 422,
                code: 'PAYMENT_REFUSED',
            );
        }

        return $this->successResponse(
            data: array_merge($intent->toArray(), [
                'order_number' => $order->order_number,
            ]),
            message: $intent->requiresRedirect()
                ? 'Continue to the payment provider.'
                : 'No payment is required now.',
            status: 201,
        );
    }

    /**
     * GET|POST /payments/{gateway}/callback/{payment}/{outcome}
     *
     * Steps 5, 6, 7 — the customer returns from the gateway.
     *
     * Accepts both verbs because gateways differ: SSLCommerz POSTs its result,
     * Stripe appends a query string to a GET. Neither body is trusted.
     *
     * The `outcome` segment says which route the customer came back on. It
     * decides which page they land on, not whether they paid — the status comes
     * from PaymentService, which asks the gateway.
     */
    public function callback(
        Request $request,
        string $gateway,
        string $payment,
        string $outcome,
    ): RedirectResponse {
        $record = Payment::query()->where('uuid', $payment)->first();

        if ($record === null) {
            /*
             * An unknown payment uuid. Possibly a stale link, possibly someone
             * probing. Redirected to the failure page rather than shown a 404:
             * this is a customer-facing URL, and a raw error page is a poor
             * place to land after paying.
             */
            Log::warning('Payment callback referenced an unknown payment.', [
                'gateway' => $gateway,
                'payment' => $payment,
                'ip' => $request->ip(),
            ]);

            return redirect()->away($this->returnUrl('failure'));
        }

        try {
            $verification = $this->payments->handleCallback($request, $record, $outcome);
        } catch (PaymentException $exception) {
            Log::error('Payment callback verification failed.', array_merge(
                $exception->context(),
                ['payment' => $record->uuid],
            ));

            return redirect()->away($this->returnUrl('failure', $record));
        }

        /*
         * The landing page follows the *verified* status, not the URL the
         * gateway used. A customer redirected to the success route whose
         * payment did not actually settle lands on the failure page — which is
         * the honest outcome, and the one that stops them believing an unpaid
         * order is complete.
         */
        $destination = match (true) {
            $verification->isPaid() => 'success',
            $verification->isCancelled() => 'cancel',
            $verification->isPending() => 'success',
            default => 'failure',
        };

        return redirect()->away($this->returnUrl($destination, $record));
    }

    /**
     * POST /payments/{gateway}/webhook
     *
     * Server-to-server notification from a gateway.
     *
     * Unauthenticated at the route level and verified by signature inside the
     * gateway. Rate limiting is deliberately absent here: throttling a webhook
     * endpoint drops legitimate notifications about money, and the signature
     * check already makes an unsigned flood cheap to reject.
     */
    public function webhook(Request $request, string $gateway): JsonResponse
    {
        if (! $this->gateways->has($gateway)) {
            return $this->errorResponse(
                message: 'Unknown payment gateway.',
                status: 404,
                code: 'UNKNOWN_GATEWAY',
            );
        }

        try {
            $result = $this->payments->handleWebhook($request, $gateway);
        } catch (WebhookVerificationException $exception) {
            /*
             * 400 with a deliberately vague body.
             *
             * Telling a caller *why* their signature failed — wrong secret,
             * missing header, stale timestamp — is an oracle for constructing
             * one that passes. The detail goes to the log; the response says
             * only that it was rejected.
             */
            return response()->json([
                'success' => false,
                'message' => 'Webhook signature verification failed.',
            ], 400);
        } catch (\Throwable $exception) {
            Log::error('Webhook processing failed.', [
                'gateway' => $gateway,
                'error' => $exception->getMessage(),
            ]);

            /*
             * 500 on purpose, so the gateway retries.
             *
             * A processing failure on our side is transient — a database blip,
             * an unreachable dependency. Answering 200 would make the gateway
             * consider the notification delivered and never send it again,
             * losing the record of a payment permanently.
             */
            return response()->json([
                'success' => false,
                'message' => 'The webhook could not be processed.',
            ], 500);
        }

        /*
         * 200 for everything the application handled, including duplicates and
         * event types it ignores. A gateway reads any non-2xx as "retry", so
         * returning an error for an event we simply do not need would put the
         * endpoint into a permanent retry loop and eventually get the webhook
         * switched off.
         */
        return response()->json([
            'success' => true,
            'message' => 'Webhook received.',
            'data' => $result,
        ]);
    }

    /**
     * GET /payments/{payment}/status
     *
     * What the storefront polls while waiting for an async settlement.
     *
     * Reports the *stored* status rather than calling the gateway. A customer
     * refreshing a page must not be able to generate outbound API calls to a
     * processor — that is a free amplification vector, and rate-limited
     * gateways would start refusing legitimate traffic.
     */
    public function status(Request $request, string $payment): JsonResponse
    {
        $record = Payment::query()->where('uuid', $payment)->with('order')->first();

        if ($record === null) {
            return $this->errorResponse(
                message: 'That payment could not be found.',
                status: 404,
                code: 'PAYMENT_NOT_FOUND',
            );
        }

        return $this->successResponse(
            data: [
                'payment' => $record->uuid,
                'status' => $record->status,
                'gateway' => $record->gateway,
                'amount' => (int) $record->amount,
                'currency' => $record->currency,
                'paid_at' => $record->paid_at?->toIso8601String(),

                // Enough for the storefront to show the order, without exposing
                // the internal payment record or the gateway's raw response.
                'order' => [
                    'order_number' => $record->order?->order_number,
                    'status' => $record->order?->status->value,
                    'payment_status' => $record->order?->payment_status->value,
                ],
            ],
            message: 'Payment status retrieved.',
        );
    }

    /**
     * Where to send the customer's browser after a callback.
     *
     * The order number is appended so the storefront can render the order
     * without a second lookup keyed on something the customer would have to
     * remember.
     */
    private function returnUrl(string $outcome, ?Payment $payment = null): string
    {
        $base = (string) config('payment.return_urls.'.$outcome, config('payment.return_urls.failure'));

        if ($payment === null) {
            return $base;
        }

        $query = http_build_query(array_filter([
            'order' => $payment->order?->order_number,
            'payment' => $payment->uuid,
        ]));

        return $query === '' ? $base : $base.(str_contains($base, '?') ? '&' : '?').$query;
    }
}
