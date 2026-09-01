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

/**
 * Stripe — Checkout Sessions, called over the REST API directly.
 *
 * ## No SDK, deliberately
 *
 * `stripe/stripe-php` is excellent and would work. It is not used because this
 * integration touches four endpoints, and the SDK brings a dependency whose
 * major versions move independently of ours into the one part of the system
 * that handles money. The REST calls below are stable, documented, and legible
 * without leaving the file.
 *
 * ## Amounts
 *
 * Stripe uses integer minor units, which matches this codebase exactly — so no
 * conversion happens here at all, and the whole class of float-rounding bug
 * that {@see AbstractGateway::toMinorUnits()} exists to prevent simply does not
 * arise on this path.
 *
 * ## Webhooks are real here
 *
 * Unlike the other three gateways, Stripe signs its webhooks with an HMAC over
 * a timestamp and the raw body. That makes {@see parseWebhook()} genuinely
 * authenticating, and it is why Stripe is the gateway whose webhook path the
 * tests exercise most closely.
 *
 * The signature is verified against the **raw request body**, not a re-encoded
 * array: `json_decode` followed by `json_encode` reorders keys and normalises
 * whitespace, and the resulting bytes will not match the HMAC Stripe computed.
 */
final class StripeGateway extends AbstractGateway
{
    /** Stripe tolerates a five-minute clock skew on webhook timestamps. */
    private const WEBHOOK_TOLERANCE_SECONDS = 300;

    public function identifier(): string
    {
        return 'stripe';
    }

    public function displayName(): string
    {
        return 'Card';
    }

    /**
     * @return array<int, string>
     */
    protected function requiredCredentials(): array
    {
        return ['secret_key'];
    }

    /**
     * Stripe has one host for both modes; the key decides which.
     */
    protected function baseUrl(): string
    {
        return rtrim((string) $this->config('api_base', 'https://api.stripe.com'), '/');
    }

    /**
     * Create a Checkout Session.
     *
     * @param  array<string, mixed>  $context
     */
    public function initiate(Order $order, Payment $payment, array $context = []): PaymentIntent
    {
        if (! $this->isAvailable()) {
            throw PaymentException::notConfigured($this->identifier());
        }

        /*
         * Stripe's API is form-encoded with bracket notation for nested data,
         * even though it answers in JSON.
         */
        $form = [
            'mode' => 'payment',
            'success_url' => ($context['success_url'] ?? '').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $context['cancel_url'] ?? '',
            'client_reference_id' => $payment->uuid,
            'customer_email' => $order->customer_email,

            // Echoed back on the webhook, so an event can be matched to a
            // payment without a database lookup on an attacker-supplied value.
            'metadata[payment_uuid]' => $payment->uuid,
            'metadata[order_number]' => $order->order_number,

            // One line item for the order total rather than a line per product.
            // Stripe's line items are for *its* receipt; ours is the invoice,
            // and itemising twice invites the two to disagree after a refund.
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => strtolower($order->currency),
            'line_items[0][price_data][unit_amount]' => (int) $order->grand_total,
            'line_items[0][price_data][product_data][name]' => 'Order '.$order->order_number,
        ];

        $response = $this->authenticated()->asForm()->post('/v1/checkout/sessions', $form);

        if ($response->failed()) {
            $error = (string) $response->json('error.message', 'HTTP '.$response->status());

            /*
             * A 4xx from Stripe is a considered refusal — a bad amount, an
             * unsupported currency — and is reported as a failed intent so the
             * shopper can pick another method. A 5xx is an outage and throws,
             * because retrying is the right response to that.
             */
            if ($response->clientError()) {
                return PaymentIntent::failed(
                    gateway: $this->identifier(),
                    reason: $error,
                    raw: $this->sanitiseResponse((array) $response->json()),
                );
            }

            throw PaymentException::communicationFailed(
                $this->identifier(),
                $error,
                ['order' => $order->order_number],
            );
        }

        $body = (array) $response->json();
        $url = $body['url'] ?? null;
        $sessionId = $body['id'] ?? null;

        if (! is_string($url) || $url === '' || ! is_string($sessionId)) {
            throw PaymentException::rejected(
                $this->identifier(),
                'The Checkout Session response carried no URL.',
                context: ['order' => $order->order_number],
            );
        }

        return PaymentIntent::redirect(
            gateway: $this->identifier(),
            redirectUrl: $url,
            reference: $sessionId,
            // The publishable key is safe to expose — it is designed for
            // client-side use — and lets a storefront use Stripe.js if it
            // prefers that to a plain redirect.
            payload: ['publishable_key' => $this->config('publishable_key')],
            raw: $this->sanitiseResponse($body),
        );
    }

    /**
     * Interpret the customer's return from Stripe Checkout.
     *
     * `session_id` is read from the query string, but nothing else is: the
     * status comes from retrieving that session server-side with the secret
     * key. A customer landing on the success URL has not necessarily paid —
     * they may simply have opened the URL.
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

        $sessionId = $request->input('session_id') ?? $payment->transaction_reference;

        if (! is_string($sessionId) || $sessionId === '') {
            return PaymentVerification::failed(
                gateway: $this->identifier(),
                reason: 'Stripe returned no session reference, so the payment could not be confirmed.',
                raw: ['outcome' => $outcome],
            );
        }

        return $this->retrieveSession($sessionId, $payment);
    }

    public function verify(Payment $payment): PaymentVerification
    {
        $sessionId = $payment->transaction_reference
            ?? data_get($payment->gateway_response, 'id');

        if (! is_string($sessionId) || $sessionId === '') {
            return PaymentVerification::pending(
                gateway: $this->identifier(),
                raw: ['note' => 'No Stripe session has been recorded for this attempt yet.'],
            );
        }

        return $this->retrieveSession($sessionId, $payment);
    }

    /**
     * Retrieve a Checkout Session and read its true state.
     *
     * The payment intent is expanded in the same call so the card's brand and
     * last four digits arrive without a second round trip.
     */
    private function retrieveSession(string $sessionId, Payment $payment): PaymentVerification
    {
        $response = $this->authenticated()->get('/v1/checkout/sessions/'.urlencode($sessionId), [
            'expand' => ['payment_intent', 'payment_intent.latest_charge'],
        ]);

        if ($response->failed()) {
            throw PaymentException::communicationFailed(
                $this->identifier(),
                (string) $response->json('error.message', 'HTTP '.$response->status()),
                ['payment' => $payment->uuid],
            );
        }

        $body = (array) $response->json();
        $clean = $this->sanitiseResponse($body);

        $paymentStatus = (string) ($body['payment_status'] ?? '');
        $sessionStatus = (string) ($body['status'] ?? '');

        if ($paymentStatus === 'paid') {
            $charge = data_get($body, 'payment_intent.latest_charge');
            $card = data_get($charge, 'payment_method_details.card', []);

            return PaymentVerification::paid(
                gateway: $this->identifier(),
                // The PaymentIntent id, not the session id: it is what a refund
                // is issued against and what Stripe's dashboard searches on.
                reference: (string) (data_get($body, 'payment_intent.id') ?? $sessionId),
                // Already minor units — no conversion, no rounding.
                amount: (int) ($body['amount_total'] ?? 0),
                currency: strtoupper((string) ($body['currency'] ?? $payment->currency)),
                cardBrand: is_array($card) ? ($card['brand'] ?? null) : null,
                cardLastFour: is_array($card) ? ($card['last4'] ?? null) : null,
                raw: $clean,
            );
        }

        if ($sessionStatus === 'expired') {
            return PaymentVerification::failed(
                gateway: $this->identifier(),
                reason: 'The payment session expired before it was completed.',
                reference: $sessionId,
                raw: $clean,
            );
        }

        /*
         * `unpaid` on an open session means the customer has not finished —
         * they may still be entering card details. Pending, not failed:
         * failing here would cancel an order mid-payment.
         */
        return PaymentVerification::pending(
            gateway: $this->identifier(),
            reference: $sessionId,
            raw: $clean,
        );
    }

    /**
     * Verify a Stripe webhook signature and normalise the event.
     *
     * The scheme: `Stripe-Signature` carries a timestamp `t` and one or more
     * `v1` HMAC-SHA256 values over `{timestamp}.{raw body}`, keyed by the
     * webhook secret.
     *
     * Three checks, each closing a different hole:
     *
     * 1. **Secret configured** — without one nothing can be verified, and
     *    processing anyway means accepting unauthenticated instructions about
     *    money.
     * 2. **Timestamp within tolerance** — stops an attacker replaying a
     *    genuine, correctly-signed event indefinitely.
     * 3. **`hash_equals` on the HMAC** — a `===` comparison leaks position
     *    information through timing.
     *
     * Multiple `v1` values appear during a secret rotation; any one matching is
     * enough, which is what allows a rotation without dropped events.
     */
    public function parseWebhook(Request $request): WebhookEvent
    {
        $secret = $this->config('webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw WebhookVerificationException::notConfigured($this->identifier());
        }

        $header = $request->header('Stripe-Signature');

        if (! is_string($header) || $header === '') {
            throw WebhookVerificationException::missingSignature($this->identifier());
        }

        // The raw body, byte for byte. Re-encoding a decoded array reorders
        // keys and would never match the HMAC Stripe computed.
        $rawBody = $request->getContent();

        [$timestamp, $signatures] = $this->parseSignatureHeader($header);

        if ($timestamp === null || $signatures === []) {
            throw WebhookVerificationException::missingSignature($this->identifier());
        }

        if (abs(time() - $timestamp) > self::WEBHOOK_TOLERANCE_SECONDS) {
            throw new WebhookVerificationException(
                'Stripe webhook timestamp is outside the tolerance window.',
                ['gateway' => $this->identifier()],
            );
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        $matched = false;

        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                $matched = true;

                break;
            }
        }

        if (! $matched) {
            throw WebhookVerificationException::invalidSignature($this->identifier());
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            throw WebhookVerificationException::malformed($this->identifier(), 'body was not valid JSON');
        }

        return $this->normaliseEvent($payload);
    }

    /**
     * Split a `Stripe-Signature` header into its timestamp and v1 signatures.
     *
     * @return array{0: int|null, 1: array<int, string>}
     */
    private function parseSignatureHeader(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        return [$timestamp, $signatures];
    }

    /**
     * Map a Stripe event onto the normalised shape.
     *
     * Only the events this store acts on are mapped; everything else becomes
     * ignorable. Stripe sends dozens of event types, and returning an error for
     * one we do not need would put the endpoint into a retry loop and
     * eventually get the webhook disabled.
     *
     * @param  array<string, mixed>  $payload
     */
    private function normaliseEvent(array $payload): WebhookEvent
    {
        $type = (string) ($payload['type'] ?? '');
        $object = (array) data_get($payload, 'data.object', []);
        $eventId = $payload['id'] ?? null;

        $normalised = match ($type) {
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
            'payment_intent.succeeded' => WebhookEvent::TYPE_PAYMENT_SUCCEEDED,

            'checkout.session.async_payment_failed',
            'payment_intent.payment_failed' => WebhookEvent::TYPE_PAYMENT_FAILED,

            'checkout.session.expired' => WebhookEvent::TYPE_PAYMENT_CANCELLED,

            'charge.refunded' => WebhookEvent::TYPE_REFUND_SUCCEEDED,

            default => WebhookEvent::TYPE_UNHANDLED,
        };

        if ($normalised === WebhookEvent::TYPE_UNHANDLED) {
            return WebhookEvent::ignorable(
                gateway: $this->identifier(),
                eventId: is_string($eventId) ? $eventId : null,
                raw: ['type' => $type],
            );
        }

        /*
         * The reference differs by object type: a Checkout Session carries the
         * payment intent alongside its own id, whereas a PaymentIntent event's
         * own id is the reference. Reading `id` blindly would store a session
         * id where a payment intent id was expected and break the later lookup.
         */
        $reference = match (true) {
            isset($object['payment_intent']) && is_string($object['payment_intent']) => $object['payment_intent'],
            default => (string) ($object['id'] ?? ''),
        };

        return new WebhookEvent(
            gateway: $this->identifier(),
            type: $normalised,
            reference: $reference !== '' ? $reference : null,
            // Our own reference, echoed back from the metadata we set at
            // session creation.
            orderReference: data_get($object, 'metadata.payment_uuid')
                ?? data_get($object, 'client_reference_id'),
            amount: isset($object['amount_total'])
                ? (int) $object['amount_total']
                : (isset($object['amount']) ? (int) $object['amount'] : null),
            currency: isset($object['currency']) ? strtoupper((string) $object['currency']) : null,
            eventId: is_string($eventId) ? $eventId : null,
            raw: $this->sanitiseResponse($object),
        );
    }

    public function refund(Payment $payment, int $amount, ?string $reason = null): RefundResult
    {
        $paymentIntent = $payment->transaction_reference;

        if (! is_string($paymentIntent) || ! str_starts_with($paymentIntent, 'pi_')) {
            return RefundResult::failed(
                gateway: $this->identifier(),
                reason: 'This payment has no Stripe payment intent, so it cannot be reversed automatically.',
            );
        }

        $response = $this->authenticated()->asForm()->post('/v1/refunds', [
            'payment_intent' => $paymentIntent,
            'amount' => $amount,
            // Stripe accepts a fixed vocabulary here. An arbitrary staff note
            // would be rejected, so the free text goes into metadata instead.
            'reason' => 'requested_by_customer',
            'metadata[note]' => mb_substr($reason ?? '', 0, 500),
        ]);

        if ($response->failed()) {
            $error = (string) $response->json('error.message', 'HTTP '.$response->status());

            if ($response->clientError()) {
                return RefundResult::failed(
                    gateway: $this->identifier(),
                    reason: $error,
                    raw: $this->sanitiseResponse((array) $response->json()),
                );
            }

            throw PaymentException::communicationFailed(
                $this->identifier(),
                $error,
                ['payment' => $payment->uuid],
            );
        }

        $body = (array) $response->json();
        $status = (string) ($body['status'] ?? '');

        return match ($status) {
            'succeeded' => RefundResult::completed(
                gateway: $this->identifier(),
                reference: (string) ($body['id'] ?? ''),
                amount: (int) ($body['amount'] ?? $amount),
                currency: strtoupper((string) ($body['currency'] ?? $payment->currency)),
                raw: $this->sanitiseResponse($body),
            ),
            'pending' => RefundResult::pending(
                gateway: $this->identifier(),
                reference: $body['id'] ?? null,
                amount: (int) ($body['amount'] ?? $amount),
                raw: $this->sanitiseResponse($body),
            ),
            default => RefundResult::failed(
                gateway: $this->identifier(),
                reason: (string) ($body['failure_reason'] ?? 'Stripe refused the refund.'),
                raw: $this->sanitiseResponse($body),
            ),
        };
    }

    /**
     * An HTTP client carrying the secret key.
     */
    private function authenticated(): PendingRequest
    {
        return $this->http()
            ->withToken((string) $this->config('secret_key'))
            ->withHeaders(['Stripe-Version' => '2024-06-20']);
    }
}
