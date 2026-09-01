<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Order;
use App\Models\Payment;
use App\Payments\Contracts\PaymentGatewayInterface;
use App\Payments\Data\PaymentIntent;
use App\Payments\Data\PaymentVerification;
use App\Payments\Data\RefundResult;
use App\Payments\Data\WebhookEvent;
use App\Payments\Exceptions\WebhookVerificationException;
use Illuminate\Http\Request;

/**
 * A controllable gateway, for tests.
 *
 * ## Why a fake rather than HTTP fakes
 *
 * `Http::fake()` would let the real gateways run against canned responses, and
 * some tests do want that — StripeGatewayTest exercises the actual signature
 * verification, because that is the code under test.
 *
 * But most payment tests are about *PaymentService*: does a duplicate callback
 * settle twice, does a mismatched amount get refused, does a failed payment
 * leave the order alive. Those tests should not also be asserting that
 * SSLCommerz's response parsing works — a change to one processor's JSON shape
 * would break twenty unrelated tests, and the failure would point at the wrong
 * place.
 *
 * So this fake stands in for "a gateway" while the real ones are tested
 * separately for their own protocol handling.
 *
 * ## What it lets a test control
 *
 * Every method's outcome is settable, and every call is counted. The counts are
 * what let a test assert the thing that matters most here: that a duplicate
 * notification does **not** produce a second verification, and that a bKash-style
 * capturing call happens exactly once.
 */
final class FakeGateway implements PaymentGatewayInterface
{
    /** How many times each method was called. */
    public int $initiateCalls = 0;

    public int $verifyCalls = 0;

    public int $callbackCalls = 0;

    public int $refundCalls = 0;

    public ?PaymentIntent $nextIntent = null;

    public ?PaymentVerification $nextVerification = null;

    public ?RefundResult $nextRefund = null;

    public ?WebhookEvent $nextWebhookEvent = null;

    /** When set, parseWebhook throws it — for the invalid-signature tests. */
    public ?WebhookVerificationException $webhookException = null;

    public bool $available = true;

    public bool $offline = false;

    public bool $refundable = true;

    public function __construct(
        private readonly string $identifier = 'fake',
    ) {}

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function displayName(): string
    {
        return 'Fake Gateway';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function isOffline(): bool
    {
        return $this->offline;
    }

    public function supportsRefunds(): bool
    {
        return $this->refundable;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function initiate(Order $order, Payment $payment, array $context = []): PaymentIntent
    {
        $this->initiateCalls++;

        return $this->nextIntent ?? PaymentIntent::redirect(
            gateway: $this->identifier,
            redirectUrl: 'https://gateway.test/pay/'.$payment->uuid,
            reference: 'ref_'.$payment->uuid,
            raw: ['fake' => true],
        );
    }

    public function handleCallback(Request $request, Payment $payment, string $outcome): PaymentVerification
    {
        $this->callbackCalls++;

        if ($outcome === 'cancel') {
            return PaymentVerification::cancelled($this->identifier, $payment->transaction_reference);
        }

        return $this->verify($payment);
    }

    public function verify(Payment $payment): PaymentVerification
    {
        $this->verifyCalls++;

        return $this->nextVerification ?? PaymentVerification::paid(
            gateway: $this->identifier,
            reference: $payment->transaction_reference ?? 'ref_'.$payment->uuid,
            amount: (int) $payment->amount,
            currency: $payment->currency,
            raw: ['fake' => true],
        );
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        if ($this->webhookException !== null) {
            throw $this->webhookException;
        }

        return $this->nextWebhookEvent ?? new WebhookEvent(
            gateway: $this->identifier,
            type: WebhookEvent::TYPE_PAYMENT_SUCCEEDED,
            reference: $request->input('reference'),
            orderReference: $request->input('payment_uuid'),
            amount: $request->integer('amount') ?: null,
            eventId: $request->input('event_id'),
            raw: $request->all(),
        );
    }

    public function refund(Payment $payment, int $amount, ?string $reason = null): RefundResult
    {
        $this->refundCalls++;

        return $this->nextRefund ?? RefundResult::completed(
            gateway: $this->identifier,
            reference: 'refund_'.$payment->uuid,
            amount: $amount,
            currency: $payment->currency,
            raw: ['fake' => true],
        );
    }

    /**
     * Forget the call counts, keeping the configured outcomes.
     */
    public function resetCounts(): void
    {
        $this->initiateCalls = 0;
        $this->verifyCalls = 0;
        $this->callbackCalls = 0;
        $this->refundCalls = 0;
    }
}
