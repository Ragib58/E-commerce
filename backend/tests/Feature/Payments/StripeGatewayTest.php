<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Payments\Data\WebhookEvent;
use App\Payments\Exceptions\WebhookVerificationException;
use App\Payments\Gateways\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Stripe's own protocol handling.
 *
 * Separate from PaymentFlowTest, which uses a fake gateway. Here the code under
 * test *is* the integration: the HMAC verification, the Checkout Session
 * parsing, the mapping of Stripe's event vocabulary onto ours.
 *
 * Stripe is the gateway worth testing this closely because it is the only one
 * of the four with a real signed-webhook scheme — so it is where a signature
 * mistake would actually be exploitable.
 */
final class StripeGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret_value';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('payment.stripe', [
            'enabled' => true,
            'secret_key' => 'sk_test_key',
            'publishable_key' => 'pk_test_key',
            'webhook_secret' => self::SECRET,
            'api_base' => 'https://api.stripe.com',
        ]);
    }

    private function gateway(): StripeGateway
    {
        return new StripeGateway;
    }

    /**
     * Build a request carrying a correctly signed Stripe webhook.
     */
    private function signedRequest(array $payload, ?int $timestamp = null, ?string $secret = null): Request
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp ??= time();

        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? self::SECRET);

        $request = Request::create('/webhook', 'POST', [], [], [], [], $body);
        $request->headers->set('Stripe-Signature', sprintf('t=%d,v1=%s', $timestamp, $signature));

        return $request;
    }

    /*
    |--------------------------------------------------------------------------
    | Webhook signature verification
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_correctly_signed_webhook_is_accepted(): void
    {
        $event = $this->gateway()->parseWebhook($this->signedRequest([
            'id' => 'evt_123',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_123',
                'payment_intent' => 'pi_123',
                'amount_total' => 10_000,
                'currency' => 'usd',
                'metadata' => ['payment_uuid' => 'abc-123'],
            ]],
        ]));

        $this->assertSame(WebhookEvent::TYPE_PAYMENT_SUCCEEDED, $event->type);

        // The payment intent, not the session id — it is what a refund is
        // issued against and what Stripe's dashboard searches on.
        $this->assertSame('pi_123', $event->reference);
        $this->assertSame('abc-123', $event->orderReference);
        $this->assertSame(10_000, $event->amount);
    }

    #[Test]
    public function a_webhook_with_a_wrong_signature_is_rejected(): void
    {
        $request = $this->signedRequest(
            ['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => []]],
            secret: 'whsec_the_wrong_secret',
        );

        $this->expectException(WebhookVerificationException::class);

        $this->gateway()->parseWebhook($request);
    }

    #[Test]
    public function a_webhook_with_no_signature_header_is_rejected(): void
    {
        $request = Request::create('/webhook', 'POST', [], [], [], [], '{"id":"evt_1"}');

        $this->expectException(WebhookVerificationException::class);

        $this->gateway()->parseWebhook($request);
    }

    #[Test]
    public function a_replayed_webhook_outside_the_tolerance_window_is_rejected(): void
    {
        /*
         * The signature is genuine — this is a real event Stripe sent. The
         * timestamp check is what stops an attacker capturing it and replaying
         * it indefinitely.
         */
        $request = $this->signedRequest(
            ['id' => 'evt_old', 'type' => 'checkout.session.completed', 'data' => ['object' => []]],
            timestamp: time() - 3600,
        );

        $this->expectException(WebhookVerificationException::class);

        $this->gateway()->parseWebhook($request);
    }

    #[Test]
    public function a_webhook_is_rejected_when_no_secret_is_configured(): void
    {
        /*
         * Without a secret nothing can be verified, and processing anyway would
         * mean accepting unauthenticated instructions about money. Refusing is
         * the only safe default.
         */
        config()->set('payment.stripe.webhook_secret', null);

        $this->expectException(WebhookVerificationException::class);

        $this->gateway()->parseWebhook($this->signedRequest([
            'id' => 'evt_1',
            'type' => 'checkout.session.completed',
            'data' => ['object' => []],
        ]));
    }

    #[Test]
    public function the_signature_is_verified_against_the_raw_body(): void
    {
        /*
         * Re-encoding a decoded array reorders keys and normalises whitespace,
         * and the resulting bytes will not match the HMAC Stripe computed. This
         * asserts the raw body is what gets hashed — a payload whose key order
         * differs from its canonical encoding still verifies.
         */
        $body = '{"type":"checkout.session.completed","id":"evt_order","data":{"object":{"id":"cs_1"}}}';
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);

        $request = Request::create('/webhook', 'POST', [], [], [], [], $body);
        $request->headers->set('Stripe-Signature', sprintf('t=%d,v1=%s', $timestamp, $signature));

        $event = $this->gateway()->parseWebhook($request);

        $this->assertSame(WebhookEvent::TYPE_PAYMENT_SUCCEEDED, $event->type);
    }

    #[Test]
    public function multiple_signatures_are_accepted_during_a_secret_rotation(): void
    {
        // Stripe sends several v1 values while a secret is being rotated. Any
        // one matching is enough — otherwise a rotation drops events.
        $body = json_encode(['id' => 'evt_rot', 'type' => 'checkout.session.completed', 'data' => ['object' => []]], JSON_THROW_ON_ERROR);
        $timestamp = time();

        $wrong = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_old');
        $right = hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);

        $request = Request::create('/webhook', 'POST', [], [], [], [], $body);
        $request->headers->set('Stripe-Signature', sprintf('t=%d,v1=%s,v1=%s', $timestamp, $wrong, $right));

        $this->assertSame(
            WebhookEvent::TYPE_PAYMENT_SUCCEEDED,
            $this->gateway()->parseWebhook($request)->type,
        );
    }

    #[Test]
    public function an_unrecognised_event_type_is_ignorable_rather_than_an_error(): void
    {
        /*
         * Stripe sends dozens of event types. Erroring on one we do not act on
         * would put the endpoint into a retry loop and eventually get the
         * webhook disabled.
         */
        $event = $this->gateway()->parseWebhook($this->signedRequest([
            'id' => 'evt_noise',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => []],
        ]));

        $this->assertTrue($event->isIgnorable());
    }

    #[Test]
    public function a_failed_payment_event_is_mapped_to_failure(): void
    {
        $event = $this->gateway()->parseWebhook($this->signedRequest([
            'id' => 'evt_fail',
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => ['id' => 'pi_failed', 'amount' => 5_000, 'currency' => 'usd']],
        ]));

        $this->assertTrue($event->indicatesFailure());
        $this->assertSame('pi_failed', $event->reference);
    }

    /*
    |--------------------------------------------------------------------------
    | Session creation and verification
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function initiating_returns_the_checkout_session_url(): void
    {
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_test_123',
                'url' => 'https://checkout.stripe.com/pay/cs_test_123',
            ]),
        ]);

        $order = Order::factory()->totals(10_000)->create();
        $payment = Payment::factory()->forOrder($order)->create();

        $intent = $this->gateway()->initiate($order, $payment, [
            'success_url' => 'https://shop.test/success',
            'cancel_url' => 'https://shop.test/cancel',
        ]);

        $this->assertTrue($intent->requiresRedirect());
        $this->assertSame('https://checkout.stripe.com/pay/cs_test_123', $intent->redirectUrl);
        $this->assertSame('cs_test_123', $intent->reference);
    }

    #[Test]
    public function initiation_sends_the_amount_in_minor_units_without_conversion(): void
    {
        /*
         * Stripe uses integer minor units, which matches this codebase exactly.
         * The whole class of float-rounding bug is avoided by there being no
         * conversion at all on this path — asserted, because a well-meaning
         * refactor to "normalise" amounts would reintroduce it.
         */
        Http::fake([
            'api.stripe.com/*' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/x']),
        ]);

        $order = Order::factory()->totals(12_345)->create();
        $payment = Payment::factory()->forOrder($order)->create();

        $this->gateway()->initiate($order, $payment, []);

        Http::assertSent(function ($request): bool {
            return $request['line_items[0][price_data][unit_amount]'] === 12_345;
        });
    }

    #[Test]
    public function a_paid_session_verifies_as_paid(): void
    {
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/*' => Http::response([
                'id' => 'cs_paid',
                'payment_status' => 'paid',
                'status' => 'complete',
                'amount_total' => 10_000,
                'currency' => 'usd',
                'payment_intent' => [
                    'id' => 'pi_paid',
                    'latest_charge' => [
                        'payment_method_details' => ['card' => ['brand' => 'visa', 'last4' => '4242']],
                    ],
                ],
            ]),
        ]);

        $order = Order::factory()->totals(10_000)->create();
        $payment = Payment::factory()->forOrder($order)->create(['transaction_reference' => 'cs_paid']);

        $verification = $this->gateway()->verify($payment);

        $this->assertTrue($verification->isPaid());
        $this->assertSame('pi_paid', $verification->reference);
        $this->assertSame(10_000, $verification->amount);

        // Display fragments only — never the instrument itself.
        $this->assertSame('visa', $verification->cardBrand);
        $this->assertSame('4242', $verification->cardLastFour);
    }

    #[Test]
    public function an_unpaid_open_session_verifies_as_pending(): void
    {
        // The customer may still be entering card details. Failing here would
        // cancel an order mid-payment.
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/*' => Http::response([
                'id' => 'cs_open',
                'payment_status' => 'unpaid',
                'status' => 'open',
            ]),
        ]);

        $order = Order::factory()->create();
        $payment = Payment::factory()->forOrder($order)->create(['transaction_reference' => 'cs_open']);

        $this->assertTrue($this->gateway()->verify($payment)->isPending());
    }

    #[Test]
    public function an_expired_session_verifies_as_failed(): void
    {
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/*' => Http::response([
                'id' => 'cs_expired',
                'payment_status' => 'unpaid',
                'status' => 'expired',
            ]),
        ]);

        $order = Order::factory()->create();
        $payment = Payment::factory()->forOrder($order)->create(['transaction_reference' => 'cs_expired']);

        $this->assertTrue($this->gateway()->verify($payment)->isFailed());
    }

    /*
    |--------------------------------------------------------------------------
    | Availability
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_gateway_with_no_secret_key_is_unavailable(): void
    {
        /*
         * Enabled but not configured must read as unavailable, so the method is
         * absent from checkout rather than offered and then failing when a
         * customer tries to pay.
         */
        config()->set('payment.stripe.secret_key', null);

        $this->assertFalse($this->gateway()->isAvailable());
    }

    #[Test]
    public function a_disabled_gateway_is_unavailable_even_with_credentials(): void
    {
        config()->set('payment.stripe.enabled', false);

        $this->assertFalse($this->gateway()->isAvailable());
    }

    /*
    |--------------------------------------------------------------------------
    | Refunds
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_refund_is_issued_against_the_payment_intent(): void
    {
        Http::fake([
            'api.stripe.com/v1/refunds' => Http::response([
                'id' => 're_123',
                'status' => 'succeeded',
                'amount' => 5_000,
                'currency' => 'usd',
            ]),
        ]);

        $order = Order::factory()->totals(10_000)->create();
        $payment = Payment::factory()->forOrder($order)->paid()->create([
            'transaction_reference' => 'pi_refundable',
        ]);

        $result = $this->gateway()->refund($payment, 5_000, 'Customer returned one item.');

        $this->assertTrue($result->isCompleted());
        $this->assertSame('re_123', $result->reference);
        $this->assertSame(5_000, $result->amount);
    }

    #[Test]
    public function a_payment_without_a_payment_intent_cannot_be_refunded(): void
    {
        // A session id is not a payment intent. Sending one to Stripe's refund
        // endpoint would fail; refusing locally is clearer and cheaper.
        $order = Order::factory()->create();
        $payment = Payment::factory()->forOrder($order)->paid()->create([
            'transaction_reference' => 'cs_not_a_payment_intent',
        ]);

        $result = $this->gateway()->refund($payment, 1_000);

        $this->assertTrue($result->isFailed());
    }

    #[Test]
    public function the_stored_gateway_response_has_no_credentials_in_it(): void
    {
        /*
         * `gateway_response` is readable by any admin holding `view_payments`,
         * and processors echo back more than they should. A credential
         * persisted here would be in the database, in every backup, and on an
         * admin's screen.
         */
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_secretive',
                'url' => 'https://checkout.stripe.com/x',
                'client_secret' => 'cs_secret_should_not_persist',
                'api_key' => 'sk_live_should_not_persist',
            ]),
        ]);

        $order = Order::factory()->create();
        $payment = Payment::factory()->forOrder($order)->create();

        $intent = $this->gateway()->initiate($order, $payment, []);

        $encoded = json_encode($intent->raw, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('cs_secret_should_not_persist', $encoded);
        $this->assertStringNotContainsString('sk_live_should_not_persist', $encoded);
        $this->assertStringContainsString('[redacted]', $encoded);
    }
}
