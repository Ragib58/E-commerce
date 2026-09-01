<?php

declare(strict_types=1);

namespace App\Payments\Gateways;

use App\Models\Order;
use App\Models\Payment;
use App\Payments\Data\PaymentIntent;
use App\Payments\Data\PaymentVerification;
use App\Payments\Data\RefundResult;
use App\Payments\Data\WebhookEvent;
use App\Payments\Exceptions\PaymentException;
use App\Payments\Exceptions\WebhookVerificationException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * bKash — tokenised checkout, the dominant mobile wallet in Bangladesh.
 *
 * ## Two things make this gateway different from the others
 *
 * **Every call needs a grant token.** bKash issues a short-lived bearer token
 * from `/token/grant`, and it is rate limited — requesting one per API call
 * gets an integration throttled. It is therefore cached, with a TTL
 * deliberately shorter than the token's real lifetime so a token cannot expire
 * between our cache read and bKash's validation.
 *
 * **Payment is create-then-execute.** `/create` returns a `paymentID` and a
 * URL; the customer authorises on their side; then `/execute` actually moves
 * the money. A payment that is created but never executed has taken nothing —
 * which is exactly the state an abandoned checkout leaves behind, and exactly
 * the state that must not be mistaken for success.
 *
 * ## Where the money is decided
 *
 * `/execute` is a **capturing** call, so it cannot be used as a status check —
 * calling it twice risks double-charging. So:
 *
 * - The callback path executes **once**, on the customer's return, and only for
 *   a payment not already settled.
 * - {@see verify()} — which must be safe to call repeatedly — uses the
 *   read-only `/payment/status` endpoint instead.
 *
 * That split is why `handleCallback()` does not simply delegate to `verify()`
 * the way the other gateways do. Getting it wrong in the other direction, by
 * routing retries through `/execute`, is how a store charges a customer twice.
 */
final class BkashGateway extends AbstractGateway
{
    public function identifier(): string
    {
        return 'bkash';
    }

    public function displayName(): string
    {
        return 'bKash';
    }

    /**
     * @return array<int, string>
     */
    protected function requiredCredentials(): array
    {
        return ['app_key', 'app_secret', 'username', 'password'];
    }

    /**
     * Create a payment and hand back bKash's authorisation URL.
     *
     * @param  array<string, mixed>  $context
     */
    public function initiate(Order $order, Payment $payment, array $context = []): PaymentIntent
    {
        if (! $this->isAvailable()) {
            throw PaymentException::notConfigured($this->identifier());
        }

        $response = $this->authenticated()->post('/checkout/create', [
            'mode' => '0011',
            'payerReference' => $order->customer_phone ?? $order->order_number,
            'callbackURL' => $context['callback_url'] ?? '',
            'amount' => $this->toMajorUnits((int) $order->grand_total),
            'currency' => $order->currency,
            'intent' => 'sale',

            /*
             * Our own reference, echoed back on every subsequent call. The
             * payment uuid rather than the order number, so a retry after a
             * failed attempt is distinguishable from the attempt it replaced.
             */
            'merchantInvoiceNumber' => $payment->uuid,
        ]);

        if ($response->failed()) {
            throw PaymentException::communicationFailed(
                $this->identifier(),
                'create returned HTTP '.$response->status(),
                ['order' => $order->order_number],
            );
        }

        $body = (array) $response->json();

        /*
         * bKash reports application errors inside a 200 with a
         * `statusCode`/`errorCode` field. A gateway that only checked the HTTP
         * status would treat a refusal as a success and redirect the customer
         * to nothing.
         */
        if (($body['statusCode'] ?? null) !== '0000' && isset($body['statusCode'])) {
            return PaymentIntent::failed(
                gateway: $this->identifier(),
                reason: (string) ($body['statusMessage'] ?? 'bKash refused to create the payment.'),
                raw: $this->sanitiseResponse($body),
            );
        }

        $paymentId = $body['paymentID'] ?? null;
        $redirectUrl = $body['bkashURL'] ?? null;

        if (! is_string($paymentId) || $paymentId === '' || ! is_string($redirectUrl) || $redirectUrl === '') {
            return PaymentIntent::failed(
                gateway: $this->identifier(),
                reason: (string) ($body['errorMessage'] ?? 'bKash did not return a payment session.'),
                raw: $this->sanitiseResponse($body),
            );
        }

        return PaymentIntent::redirect(
            gateway: $this->identifier(),
            redirectUrl: $redirectUrl,
            // Stored on the payment: every later call needs this id.
            reference: $paymentId,
            raw: $this->sanitiseResponse($body),
        );
    }

    /**
     * Handle the customer's return, executing the payment exactly once.
     *
     * The `status` query parameter bKash appends is used only to detect an
     * explicit cancellation or failure, where there is nothing to execute. On
     * the success path it is ignored entirely — the money is decided by what
     * `/execute` returns, not by what the URL said.
     */
    public function handleCallback(Request $request, Payment $payment, string $outcome): PaymentVerification
    {
        $paymentId = $request->input('paymentID') ?? $payment->transaction_reference;

        $status = strtolower((string) $request->input('status', ''));

        if ($outcome === 'cancel' || $status === 'cancel') {
            return PaymentVerification::cancelled(
                gateway: $this->identifier(),
                reference: is_string($paymentId) ? $paymentId : null,
                raw: ['outcome' => $outcome, 'status' => $status],
            );
        }

        if ($status === 'failure' || $outcome === 'failure') {
            return PaymentVerification::failed(
                gateway: $this->identifier(),
                reason: 'The payment was not completed.',
                reference: is_string($paymentId) ? $paymentId : null,
                raw: ['outcome' => $outcome, 'status' => $status],
            );
        }

        if (! is_string($paymentId) || $paymentId === '') {
            return PaymentVerification::failed(
                gateway: $this->identifier(),
                reason: 'bKash returned no payment id, so the payment could not be confirmed.',
                raw: ['outcome' => $outcome],
            );
        }

        /*
         * Guard against a re-executed capture.
         *
         * A customer refreshing the return page, or a duplicated callback,
         * would otherwise call the capturing endpoint a second time. The
         * read-only status query answers instead.
         */
        if ($payment->isPaid()) {
            return $this->queryStatus($paymentId, $payment);
        }

        return $this->execute($paymentId, $payment);
    }

    /**
     * Ask bKash what state a payment is in.
     *
     * Read-only, and therefore the method used everywhere a repeat call is
     * possible — webhook retries, admin re-checks, reconciliation.
     */
    public function verify(Payment $payment): PaymentVerification
    {
        $paymentId = $payment->transaction_reference
            ?? data_get($payment->gateway_response, 'paymentID');

        if (! is_string($paymentId) || $paymentId === '') {
            return PaymentVerification::pending(
                gateway: $this->identifier(),
                raw: ['note' => 'No bKash payment id has been recorded for this attempt yet.'],
            );
        }

        return $this->queryStatus($paymentId, $payment);
    }

    /**
     * Capture the money. Called at most once per payment.
     */
    private function execute(string $paymentId, Payment $payment): PaymentVerification
    {
        $response = $this->authenticated()->post('/checkout/execute', [
            'paymentID' => $paymentId,
        ]);

        if ($response->failed()) {
            throw PaymentException::communicationFailed(
                $this->identifier(),
                'execute returned HTTP '.$response->status(),
                ['payment' => $payment->uuid],
            );
        }

        return $this->interpret((array) $response->json(), $paymentId, $payment);
    }

    /**
     * The read-only status query.
     */
    private function queryStatus(string $paymentId, Payment $payment): PaymentVerification
    {
        $response = $this->authenticated()->post('/checkout/payment/status', [
            'paymentID' => $paymentId,
        ]);

        if ($response->failed()) {
            throw PaymentException::communicationFailed(
                $this->identifier(),
                'status query returned HTTP '.$response->status(),
                ['payment' => $payment->uuid],
            );
        }

        return $this->interpret((array) $response->json(), $paymentId, $payment);
    }

    /**
     * Turn a bKash response into a verdict.
     *
     * Shared by execute and status because both return the same shape — which
     * is what lets the capturing and read-only paths agree about what
     * "Completed" means.
     *
     * @param  array<string, mixed>  $body
     */
    private function interpret(array $body, string $paymentId, Payment $payment): PaymentVerification
    {
        $clean = $this->sanitiseResponse($body);
        $status = strtolower((string) ($body['transactionStatus'] ?? ''));

        if ($status === 'completed') {
            return PaymentVerification::paid(
                gateway: $this->identifier(),
                // trxID is the customer-facing receipt number; paymentID is the
                // session. The trxID is what a support call quotes.
                reference: (string) ($body['trxID'] ?? $paymentId),
                amount: $this->toMinorUnits($body['amount'] ?? 0),
                currency: (string) ($body['currency'] ?? $payment->currency),
                raw: $clean,
            );
        }

        /*
         * "Initiated" means created but not authorised — an abandoned payment
         * sits here. Pending rather than failed: the customer may still be
         * completing it on their phone, and failing the order out from under
         * them would be wrong.
         */
        if (in_array($status, ['initiated', 'pending'], strict: true)) {
            return PaymentVerification::pending(
                gateway: $this->identifier(),
                reference: $paymentId,
                raw: $clean,
            );
        }

        return PaymentVerification::failed(
            gateway: $this->identifier(),
            reason: (string) ($body['statusMessage'] ?? $body['errorMessage'] ?? 'The payment was not completed.'),
            reference: $paymentId,
            raw: $clean,
        );
    }

    /**
     * bKash does not send signed webhooks.
     *
     * Some integrations expose a merchant-configured URL, but there is no
     * documented signature scheme to verify it with — and an unsigned webhook
     * is an unauthenticated request claiming an order was paid.
     *
     * Refused rather than trusted. bKash's flow does not need it: the callback
     * executes the payment, and {@see verify()} covers reconciliation for
     * customers who never return.
     */
    public function parseWebhook(Request $request): WebhookEvent
    {
        throw WebhookVerificationException::notConfigured($this->identifier());
    }

    public function refund(Payment $payment, int $amount, ?string $reason = null): RefundResult
    {
        $paymentId = $payment->transaction_reference
            ?? data_get($payment->gateway_response, 'paymentID');

        $trxId = data_get($payment->gateway_response, 'trxID');

        if (! is_string($trxId) || $trxId === '') {
            return RefundResult::failed(
                gateway: $this->identifier(),
                reason: 'This payment has no bKash transaction id, so it cannot be reversed automatically.',
            );
        }

        $response = $this->authenticated()->post('/checkout/payment/refund', [
            'paymentID' => $paymentId,
            'trxID' => $trxId,
            'amount' => $this->toMajorUnits($amount),
            'sku' => 'order',
            'reason' => mb_substr($reason ?? 'Refund issued by the store.', 0, 255),
        ]);

        if ($response->failed()) {
            throw PaymentException::communicationFailed(
                $this->identifier(),
                'refund returned HTTP '.$response->status(),
                ['payment' => $payment->uuid],
            );
        }

        $body = (array) $response->json();
        $status = strtolower((string) ($body['transactionStatus'] ?? ''));

        if ($status === 'completed') {
            return RefundResult::completed(
                gateway: $this->identifier(),
                reference: (string) ($body['refundTrxID'] ?? $trxId),
                amount: $amount,
                currency: $payment->currency,
                raw: $this->sanitiseResponse($body),
            );
        }

        return RefundResult::failed(
            gateway: $this->identifier(),
            reason: (string) ($body['statusMessage'] ?? $body['errorMessage'] ?? 'bKash refused the refund.'),
            raw: $this->sanitiseResponse($body),
        );
    }

    /**
     * An HTTP client carrying a valid grant token.
     */
    private function authenticated(): PendingRequest
    {
        return $this->http()->withHeaders([
            'Authorization' => $this->grantToken(),
            'X-App-Key' => (string) $this->config('app_key'),
        ]);
    }

    /**
     * Fetch a grant token, cached.
     *
     * bKash rate limits `/token/grant`, so requesting one per API call gets an
     * integration throttled. The cached TTL is shorter than the token's actual
     * lifetime — expiry between our read and bKash's check would surface as an
     * authentication failure in the middle of a customer's payment.
     *
     * The cache key includes the app key so switching credentials, or running
     * sandbox and live side by side, cannot serve a token issued for the other.
     */
    private function grantToken(): string
    {
        $cacheKey = 'payment:bkash:token:'.md5((string) $this->config('app_key'));

        $token = Cache::remember(
            $cacheKey,
            (int) $this->config('token_cache_ttl', 3000),
            function (): string {
                $response = $this->http()
                    ->withHeaders([
                        'username' => (string) $this->config('username'),
                        'password' => (string) $this->config('password'),
                    ])
                    ->post('/token/grant', [
                        'app_key' => $this->config('app_key'),
                        'app_secret' => $this->config('app_secret'),
                    ]);

                if ($response->failed()) {
                    throw PaymentException::communicationFailed(
                        $this->identifier(),
                        'token grant returned HTTP '.$response->status(),
                    );
                }

                $token = $response->json('id_token');

                if (! is_string($token) || $token === '') {
                    throw PaymentException::rejected(
                        $this->identifier(),
                        'The token grant response carried no id_token.',
                    );
                }

                return $token;
            },
        );

        return $token;
    }
}
