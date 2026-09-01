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
use Illuminate\Http\Request;

/**
 * SSLCommerz — hosted payment page, widely used in Bangladesh.
 *
 * ## Flow
 *
 * 1. POST the order to `/gwprocess/v4/api.php`; it answers with a
 *    `GatewayPageURL`.
 * 2. The customer's browser goes there and pays.
 * 3. SSLCommerz redirects the browser back to our success/fail/cancel URL, and
 *    separately POSTs an IPN to our webhook endpoint.
 * 4. Either trigger causes a `validationserverAPI` lookup, which is what
 *    actually decides the payment's status.
 *
 * ## The `val_id` trap
 *
 * SSLCommerz's browser redirect includes `status=VALID` in the POST body. It is
 * tempting — and wrong — to read it. That field arrives through the customer's
 * machine, and the whole redirect is forgeable by anyone who knows the store's
 * return URL.
 *
 * The only field taken from the redirect is `val_id`, and it is used purely as
 * a lookup key against the validation API using the store password, which the
 * customer does not have. Whatever *that* call says is the payment's status.
 * SSLCommerz's own documentation is explicit that skipping this step leaves a
 * store open to fabricated success callbacks.
 *
 * ## IPN authenticity
 *
 * SSLCommerz signs its IPN with `verify_sign` (an MD5 over the listed fields
 * plus the store password hash). The signature is checked in
 * {@see parseWebhook()} — but the IPN's own `status` still is not trusted, and
 * the handler re-validates by `val_id` exactly as the browser path does. The
 * signature proves origin; the lookup proves the amount.
 */
final class SslCommerzGateway extends AbstractGateway
{
    public function identifier(): string
    {
        return 'sslcommerz';
    }

    public function displayName(): string
    {
        return 'SSLCommerz';
    }

    /**
     * @return array<int, string>
     */
    protected function requiredCredentials(): array
    {
        return ['store_id', 'store_password'];
    }

    /**
     * Create a hosted payment session.
     *
     * @param  array<string, mixed>  $context
     */
    public function initiate(Order $order, Payment $payment, array $context = []): PaymentIntent
    {
        if (! $this->isAvailable()) {
            throw PaymentException::notConfigured($this->identifier());
        }

        $shipping = $order->shippingAddress()->first();

        $response = $this->http()->asForm()->post('/gwprocess/v4/api.php', [
            'store_id' => $this->config('store_id'),
            'store_passwd' => $this->config('store_password'),

            // SSLCommerz expects major units as a decimal string.
            'total_amount' => $this->toMajorUnits((int) $order->grand_total),
            'currency' => $order->currency,

            /*
             * Our own identifier for the attempt, echoed back on every
             * callback and IPN. The payment's uuid rather than the order
             * number, because an order may have several attempts and each must
             * be distinguishable — a retry after a decline would otherwise be
             * indistinguishable from the attempt that failed.
             */
            'tran_id' => $payment->uuid,

            'success_url' => $context['success_url'] ?? '',
            'fail_url' => $context['failure_url'] ?? '',
            'cancel_url' => $context['cancel_url'] ?? '',
            'ipn_url' => $context['webhook_url'] ?? '',

            'cus_name' => $order->customer_name,
            'cus_email' => $order->customer_email,
            'cus_phone' => $order->customer_phone ?? 'N/A',
            'cus_add1' => $shipping?->line1 ?? 'N/A',
            'cus_city' => $shipping?->city ?? 'N/A',
            'cus_postcode' => $shipping?->postal_code ?? 'N/A',
            'cus_country' => $shipping?->country ?? 'BD',

            'shipping_method' => $order->shipping_method_name ?? 'NO',
            'num_of_item' => $order->items()->count(),
            'product_name' => $this->productSummary($order),
            'product_category' => 'general',
            'product_profile' => 'general',
        ]);

        if ($response->failed()) {
            throw PaymentException::communicationFailed(
                $this->identifier(),
                'HTTP '.$response->status(),
                ['order' => $order->order_number],
            );
        }

        $body = (array) $response->json();

        /*
         * SSLCommerz reports application-level failure inside a 200 response.
         * Checking only the HTTP status would treat "your store is suspended"
         * as a success and hand the customer a redirect URL that is not there.
         */
        if (($body['status'] ?? null) !== 'SUCCESS') {
            return PaymentIntent::failed(
                gateway: $this->identifier(),
                reason: (string) ($body['failedreason'] ?? 'The payment provider refused to start the session.'),
                raw: $this->sanitiseResponse($body),
            );
        }

        $redirectUrl = $body['GatewayPageURL'] ?? null;

        if (! is_string($redirectUrl) || $redirectUrl === '') {
            throw PaymentException::rejected(
                $this->identifier(),
                'The session was created but carried no GatewayPageURL.',
                context: ['order' => $order->order_number],
            );
        }

        return PaymentIntent::redirect(
            gateway: $this->identifier(),
            redirectUrl: $redirectUrl,
            reference: $payment->uuid,
            raw: $this->sanitiseResponse($body),
        );
    }

    /**
     * Interpret the customer's return.
     *
     * Note what is *not* read: `status`. See the class docblock — that field
     * came through the customer's browser. Only `val_id` is taken, and only as
     * a key for the server-side lookup.
     */
    public function handleCallback(Request $request, Payment $payment, string $outcome): PaymentVerification
    {
        if ($outcome === 'cancel') {
            return PaymentVerification::cancelled(
                gateway: $this->identifier(),
                reference: $payment->transaction_reference,
                raw: ['outcome' => 'cancel'],
            );
        }

        $valId = $request->input('val_id');

        /*
         * No val_id means no transaction to look up. On the failure route that
         * is expected — SSLCommerz issues no validation id for a payment that
         * never completed. On the success route it means the callback is
         * malformed or forged, and the correct response is still "not paid".
         */
        if (! is_string($valId) || $valId === '') {
            return PaymentVerification::failed(
                gateway: $this->identifier(),
                reason: $outcome === 'failure'
                    ? 'The payment was declined by the provider.'
                    : 'The provider returned no validation reference, so the payment could not be confirmed.',
                reference: $payment->transaction_reference,
                raw: ['outcome' => $outcome],
            );
        }

        return $this->validateByValId($valId, $payment);
    }

    /**
     * Ask SSLCommerz what really happened.
     *
     * Prefers a stored `val_id` — captured when a callback or IPN first arrived
     * — and falls back to a transaction-id query when none exists yet, which is
     * the case for an admin re-checking a payment whose customer never
     * returned.
     */
    public function verify(Payment $payment): PaymentVerification
    {
        $valId = data_get($payment->gateway_response, 'val_id');

        if (is_string($valId) && $valId !== '') {
            return $this->validateByValId($valId, $payment);
        }

        return $this->queryByTransactionId($payment);
    }

    /**
     * The validation call — the authoritative one.
     *
     * Uses the store password, which only the server holds. That is precisely
     * what makes the answer trustworthy where the browser's redirect is not.
     */
    private function validateByValId(string $valId, Payment $payment): PaymentVerification
    {
        $response = $this->http()->get('/validator/api/validationserverAPI.php', [
            'val_id' => $valId,
            'store_id' => $this->config('store_id'),
            'store_passwd' => $this->config('store_password'),
            'format' => 'json',
        ]);

        if ($response->failed()) {
            throw PaymentException::communicationFailed(
                $this->identifier(),
                'validation returned HTTP '.$response->status(),
                ['payment' => $payment->uuid],
            );
        }

        $body = (array) $response->json();
        $clean = $this->sanitiseResponse($body);

        // Preserved so a later re-verification can reuse it without another
        // callback.
        $clean['val_id'] = $valId;

        $status = strtoupper((string) ($body['status'] ?? ''));

        /*
         * VALID and VALIDATED both mean settled. The second is what SSLCommerz
         * returns for a transaction validated once already, so treating only
         * VALID as success would make a duplicate callback look like a failure
         * and could move a paid order to failed.
         */
        if (in_array($status, ['VALID', 'VALIDATED'], strict: true)) {
            return PaymentVerification::paid(
                gateway: $this->identifier(),
                reference: (string) ($body['bank_tran_id'] ?? $body['tran_id'] ?? $valId),
                // `store_amount` is net of the gateway's fee. `amount` is what
                // the customer was charged, which is what the order is checked
                // against.
                amount: $this->toMinorUnits($body['amount'] ?? 0),
                currency: (string) ($body['currency'] ?? $payment->currency),
                cardBrand: $body['card_issuer'] ?? $body['card_type'] ?? null,
                cardLastFour: $this->lastFour($body['card_no'] ?? null),
                raw: $clean,
            );
        }

        if ($status === 'PENDING') {
            return PaymentVerification::pending(
                gateway: $this->identifier(),
                reference: (string) ($body['tran_id'] ?? $valId),
                raw: $clean,
            );
        }

        return PaymentVerification::failed(
            gateway: $this->identifier(),
            reason: (string) ($body['error'] ?? 'The payment was not completed.'),
            reference: (string) ($body['tran_id'] ?? $valId),
            raw: $clean,
        );
    }

    /**
     * Look a transaction up by our own reference.
     *
     * Used when no validation id was ever captured — an abandoned payment an
     * admin is checking on. SSLCommerz may return several rows for one
     * `tran_id` if the customer retried; the first settled one wins, since a
     * later failure after a success does not un-charge the card.
     */
    private function queryByTransactionId(Payment $payment): PaymentVerification
    {
        $response = $this->http()->get('/validator/api/merchantTransIDvalidationAPI.php', [
            'tran_id' => $payment->uuid,
            'store_id' => $this->config('store_id'),
            'store_passwd' => $this->config('store_password'),
            'format' => 'json',
        ]);

        if ($response->failed()) {
            throw PaymentException::communicationFailed(
                $this->identifier(),
                'transaction query returned HTTP '.$response->status(),
                ['payment' => $payment->uuid],
            );
        }

        $body = (array) $response->json();
        $elements = $body['element'] ?? [];

        if (! is_array($elements) || $elements === []) {
            return PaymentVerification::pending(
                gateway: $this->identifier(),
                reference: $payment->transaction_reference,
                raw: $this->sanitiseResponse($body),
            );
        }

        foreach ($elements as $element) {
            $status = strtoupper((string) ($element['status'] ?? ''));

            if (in_array($status, ['VALID', 'VALIDATED'], strict: true)) {
                return PaymentVerification::paid(
                    gateway: $this->identifier(),
                    reference: (string) ($element['bank_tran_id'] ?? $payment->uuid),
                    amount: $this->toMinorUnits($element['amount'] ?? 0),
                    currency: (string) ($element['currency'] ?? $payment->currency),
                    raw: $this->sanitiseResponse($element),
                );
            }
        }

        return PaymentVerification::failed(
            gateway: $this->identifier(),
            reason: 'No settled transaction was found for this payment.',
            reference: $payment->transaction_reference,
            raw: $this->sanitiseResponse($body),
        );
    }

    /**
     * Verify an IPN's signature and normalise it.
     *
     * SSLCommerz's scheme: sort the fields named in `verify_key`, append the
     * MD5 of the store password, MD5 the lot, and compare with `verify_sign`.
     *
     * The comparison uses `hash_equals` — a plain `===` on a hash comparison
     * leaks position information through timing, which is enough to forge a
     * signature given sufficient attempts.
     */
    public function parseWebhook(Request $request): WebhookEvent
    {
        $payload = $request->all();

        $signature = $payload['verify_sign'] ?? null;
        $keys = $payload['verify_key'] ?? null;

        if (! is_string($signature) || $signature === '' || ! is_string($keys) || $keys === '') {
            throw WebhookVerificationException::missingSignature($this->identifier());
        }

        $storePassword = $this->config('store_password');

        if (! is_string($storePassword) || $storePassword === '') {
            throw WebhookVerificationException::notConfigured($this->identifier());
        }

        $fields = explode(',', $keys);
        sort($fields);

        $parts = [];

        foreach ($fields as $field) {
            $field = trim($field);
            $parts[] = $field.'='.($payload[$field] ?? '');
        }

        $parts[] = 'store_passwd='.md5($storePassword);

        $expected = md5(implode('&', $parts));

        if (! hash_equals($expected, strtolower($signature))) {
            throw WebhookVerificationException::invalidSignature($this->identifier());
        }

        $status = strtoupper((string) ($payload['status'] ?? ''));

        /*
         * The type is derived from the IPN's status, but this is only routing —
         * it decides which handler runs, not whether money moved. The handler
         * re-validates by val_id before settling anything.
         */
        $type = match ($status) {
            'VALID', 'VALIDATED' => WebhookEvent::TYPE_PAYMENT_SUCCEEDED,
            'FAILED' => WebhookEvent::TYPE_PAYMENT_FAILED,
            'CANCELLED' => WebhookEvent::TYPE_PAYMENT_CANCELLED,
            default => WebhookEvent::TYPE_UNHANDLED,
        };

        return new WebhookEvent(
            gateway: $this->identifier(),
            type: $type,
            reference: (string) ($payload['tran_id'] ?? ''),
            orderReference: $payload['value_a'] ?? null,
            amount: isset($payload['amount']) ? $this->toMinorUnits($payload['amount']) : null,
            currency: $payload['currency'] ?? null,
            eventId: $payload['val_id'] ?? null,
            raw: $this->sanitiseResponse($payload),
        );
    }

    public function refund(Payment $payment, int $amount, ?string $reason = null): RefundResult
    {
        $bankTransactionId = data_get($payment->gateway_response, 'bank_tran_id')
            ?? $payment->transaction_reference;

        if (! is_string($bankTransactionId) || $bankTransactionId === '') {
            return RefundResult::failed(
                gateway: $this->identifier(),
                reason: 'This payment has no bank transaction id, so it cannot be reversed automatically.',
            );
        }

        $response = $this->http()->get('/validator/api/merchantTransIDvalidationAPI.php', [
            'bank_tran_id' => $bankTransactionId,
            'store_id' => $this->config('store_id'),
            'store_passwd' => $this->config('store_password'),
            'refund_amount' => $this->toMajorUnits($amount),
            'refund_remarks' => $reason ?? 'Refund issued by the store.',
            'format' => 'json',
        ]);

        if ($response->failed()) {
            throw PaymentException::communicationFailed(
                $this->identifier(),
                'refund returned HTTP '.$response->status(),
                ['payment' => $payment->uuid],
            );
        }

        $body = (array) $response->json();
        $status = strtoupper((string) ($body['status'] ?? ''));

        return match ($status) {
            'SUCCESS' => RefundResult::completed(
                gateway: $this->identifier(),
                reference: (string) ($body['refund_ref_id'] ?? $bankTransactionId),
                amount: $amount,
                currency: $payment->currency,
                raw: $this->sanitiseResponse($body),
            ),
            // SSLCommerz settles refunds asynchronously. Reporting this as
            // completed would tell a customer their money is back while the
            // processor has only queued it.
            'PROCESSING' => RefundResult::pending(
                gateway: $this->identifier(),
                reference: $body['refund_ref_id'] ?? null,
                amount: $amount,
                raw: $this->sanitiseResponse($body),
            ),
            default => RefundResult::failed(
                gateway: $this->identifier(),
                reason: (string) ($body['errorReason'] ?? 'The provider refused the refund.'),
                raw: $this->sanitiseResponse($body),
            ),
        };
    }

    /**
     * A short description of what was bought, for the gateway's records.
     *
     * Truncated because SSLCommerz bounds the field and an over-long value is
     * rejected at session creation — failing the payment for a cosmetic reason.
     */
    private function productSummary(Order $order): string
    {
        $names = $order->items()->limit(3)->pluck('product_name')->all();

        if ($names === []) {
            return 'Order '.$order->order_number;
        }

        return mb_substr(implode(', ', $names), 0, 240);
    }

    /**
     * The last four digits of a masked card number, or null.
     *
     * A display fragment for a receipt line. The full value is never stored —
     * see the payments migration.
     */
    private function lastFour(?string $maskedCardNumber): ?string
    {
        if ($maskedCardNumber === null || $maskedCardNumber === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $maskedCardNumber) ?? '';

        return strlen($digits) >= 4 ? substr($digits, -4) : null;
    }
}
