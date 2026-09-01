<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\Product;
use App\Payments\Data\PaymentIntent;
use App\Payments\Data\PaymentVerification;
use App\Payments\Data\WebhookEvent;
use App\Payments\Exceptions\WebhookVerificationException;
use App\Payments\PaymentGatewayManager;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeGateway;
use Tests\TestCase;

/**
 * The payment lifecycle.
 *
 * The assertions that matter most are the negative ones. A payment system that
 * can be told it was paid is not a payment system, so these tests try to settle
 * an order through every route a client controls — a forged success callback, a
 * replayed webhook, a mismatched amount — and assert the money does not move.
 *
 * A {@see FakeGateway} stands in for a processor so no test makes a network
 * call. The real gateways' own protocol handling is tested separately in
 * StripeGatewayTest, where the signature verification is the code under test.
 */
final class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private FakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();

        /*
         * Registered at runtime rather than in config, which is what
         * PaymentGatewayManager::extend exists for. The alternative — pointing
         * config at a real gateway class and faking HTTP — would make every
         * test here also a test of that processor's JSON parsing.
         */
        $this->gateway = new FakeGateway('fake');

        $manager = $this->app->make(PaymentGatewayManager::class);
        $manager->extend('fake', fn (): FakeGateway => $this->gateway);

        config()->set('payment.default', 'fake');
        config()->set('payment.method_gateways.cash_on_delivery', 'fake');
    }

    /**
     * An order with a real product line, ready to be paid.
     */
    private function orderAwaitingPayment(int $total = 10_000): Order
    {
        $product = Product::factory()->published()->create(['price' => $total, 'stock' => 10]);

        $order = Order::factory()->totals($total)->create([
            'payment_method' => \App\Enums\PaymentMethod::CashOnDelivery,
        ]);

        OrderItem::factory()->forProduct($product, 1)->create([
            'order_id' => $order->getKey(),
            'stock_was_reduced' => true,
        ]);

        return $order->refresh();
    }

    private function payments(): PaymentService
    {
        return $this->app->make(PaymentService::class);
    }

    /**
     * A payment already taken to the gateway.
     */
    private function initiatedPayment(Order $order): Payment
    {
        $this->payments()->initiate($order);

        return $order->payments()->latest('id')->firstOrFail();
    }

    /*
    |--------------------------------------------------------------------------
    | Successful payment
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_successful_payment_settles_the_order(): void
    {
        $order = $this->orderAwaitingPayment(10_000);
        $payment = $this->initiatedPayment($order);

        $this->gateway->nextVerification = PaymentVerification::paid(
            gateway: 'fake',
            reference: 'txn_success_1',
            amount: 10_000,
            currency: $order->currency,
        );

        $response = $this->get("/api/v1/payments/fake/callback/{$payment->uuid}/success");

        $response->assertRedirect();

        $payment->refresh();
        $order->refresh();

        // Step 9 — the payment row.
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame('txn_success_1', $payment->transaction_reference);
        $this->assertNotNull($payment->paid_at);
        $this->assertNotNull($payment->verified_at, 'A settlement must record when it was verified.');

        // Step 10 — the order.
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Confirmed, $order->status);
    }

    #[Test]
    public function a_successful_payment_is_recorded_in_the_orders_history(): void
    {
        $order = $this->orderAwaitingPayment();
        $payment = $this->initiatedPayment($order);

        $this->get("/api/v1/payments/fake/callback/{$payment->uuid}/success");

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->getKey(),
            'stream' => \App\Models\OrderStatusHistory::STREAM_PAYMENT,
            'to_status' => PaymentStatus::Paid->value,
        ]);
    }

    #[Test]
    public function initiating_a_payment_records_the_gateway_reference_before_redirecting(): void
    {
        /*
         * The reference must be stored *before* the customer's browser leaves,
         * not on their return. A fast gateway's webhook can arrive before the
         * redirect completes, and it would find no payment to attach itself to.
         */
        $order = $this->orderAwaitingPayment();

        $intent = $this->payments()->initiate($order);

        $payment = $order->payments()->latest('id')->firstOrFail();

        $this->assertSame($intent->reference, $payment->transaction_reference);
        $this->assertNotNull($payment->initiated_at);
        $this->assertSame(Payment::STATUS_PROCESSING, $payment->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Never trust the frontend
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_forged_success_callback_does_not_settle_the_order(): void
    {
        /*
         * The central rule. The customer hits the success URL with every field
         * a gateway might send set to "paid" — and the gateway, asked directly,
         * says the payment failed. The gateway wins.
         */
        $order = $this->orderAwaitingPayment();
        $payment = $this->initiatedPayment($order);

        $this->gateway->nextVerification = PaymentVerification::failed(
            gateway: 'fake',
            reason: 'Card declined.',
            reference: 'txn_declined',
        );

        $this->post("/api/v1/payments/fake/callback/{$payment->uuid}/success", [
            'status' => 'VALID',
            'payment_status' => 'paid',
            'amount' => 10_000,
            'transactionStatus' => 'Completed',
        ]);

        $payment->refresh();
        $order->refresh();

        $this->assertSame(Payment::STATUS_FAILED, $payment->status);
        $this->assertNotSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertNull($payment->paid_at);
    }

    #[Test]
    public function the_customer_lands_on_the_failure_page_when_verification_says_unpaid(): void
    {
        // A gateway that redirects optimistically, or a customer who edited the
        // URL. The landing page follows the verified status, not the route.
        $order = $this->orderAwaitingPayment();
        $payment = $this->initiatedPayment($order);

        $this->gateway->nextVerification = PaymentVerification::failed('fake', 'Declined.');

        $this->get("/api/v1/payments/fake/callback/{$payment->uuid}/success")
            ->assertRedirectContains('checkout/failed');
    }

    #[Test]
    public function a_payment_whose_amount_disagrees_with_the_order_is_refused(): void
    {
        /*
         * A callback pointing at the wrong transaction — or a substituted one —
         * must not settle a large order with a small payment.
         */
        $order = $this->orderAwaitingPayment(50_000);
        $payment = $this->initiatedPayment($order);

        $this->gateway->nextVerification = PaymentVerification::paid(
            gateway: 'fake',
            reference: 'txn_wrong_amount',
            amount: 500,
            currency: $order->currency,
        );

        $this->get("/api/v1/payments/fake/callback/{$payment->uuid}/success");

        $payment->refresh();
        $order->refresh();

        $this->assertSame(Payment::STATUS_FAILED, $payment->status);
        $this->assertNotSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertStringContainsString('mismatch', strtolower((string) $payment->failure_reason));
    }

    #[Test]
    public function a_verification_reporting_no_amount_is_refused(): void
    {
        // A gateway that cannot tell us what it captured cannot be checked
        // against the order, and an unverifiable amount is not a payment.
        $order = $this->orderAwaitingPayment(10_000);
        $payment = $this->initiatedPayment($order);

        $this->gateway->nextVerification = new PaymentVerification(
            gateway: 'fake',
            status: PaymentVerification::STATUS_PAID,
            reference: 'txn_no_amount',
            amount: null,
        );

        $this->get("/api/v1/payments/fake/callback/{$payment->uuid}/success");

        $this->assertSame(Payment::STATUS_FAILED, $payment->refresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Failed and cancelled payments
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_failed_payment_does_not_cancel_the_order(): void
    {
        /*
         * A declined card is very often followed by a successful retry on
         * another one. Cancelling would return the stock and leave the shopper
         * unable to complete a purchase they are actively trying to make.
         */
        $order = $this->orderAwaitingPayment();
        $payment = $this->initiatedPayment($order);

        $this->gateway->nextVerification = PaymentVerification::failed('fake', 'Insufficient funds.');

        $this->get("/api/v1/payments/fake/callback/{$payment->uuid}/failure");

        $order->refresh();

        $this->assertSame(PaymentStatus::Failed, $order->payment_status);
        $this->assertNotSame(OrderStatus::Cancelled, $order->status, 'The order must stay alive for a retry.');
        $this->assertSame('Insufficient funds.', $payment->refresh()->failure_reason);
    }

    #[Test]
    public function a_cancelled_payment_is_distinguished_from_a_failed_one(): void
    {
        /*
         * A store that chases an abandoned checkout the way it chases a decline
         * will nag people who simply decided not to buy.
         */
        $order = $this->orderAwaitingPayment();
        $payment = $this->initiatedPayment($order);

        $this->get("/api/v1/payments/fake/callback/{$payment->uuid}/cancel");

        $payment->refresh();

        $this->assertSame(Payment::STATUS_CANCELLED, $payment->status);
        $this->assertNotNull($payment->cancelled_at);

        // The order's payment status stays Pending: the customer may come
        // straight back and pay another way.
        $this->assertSame(PaymentStatus::Pending, $order->refresh()->payment_status);
    }

    #[Test]
    public function a_gateway_that_refuses_to_start_marks_the_payment_failed(): void
    {
        $order = $this->orderAwaitingPayment();

        $this->gateway->nextIntent = PaymentIntent::failed('fake', 'Store account suspended.');

        $intent = $this->payments()->initiate($order);

        $this->assertTrue($intent->isFailed);
        $this->assertSame(
            Payment::STATUS_FAILED,
            $order->payments()->latest('id')->firstOrFail()->status,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicate callbacks
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_duplicate_callback_settles_the_payment_only_once(): void
    {
        $order = $this->orderAwaitingPayment(10_000);
        $payment = $this->initiatedPayment($order);

        $this->gateway->nextVerification = PaymentVerification::paid(
            gateway: 'fake',
            reference: 'txn_dup',
            amount: 10_000,
            currency: $order->currency,
        );

        $this->gateway->resetCounts();

        // The customer refreshes the return page.
        $this->get("/api/v1/payments/fake/callback/{$payment->uuid}/success");
        $this->get("/api/v1/payments/fake/callback/{$payment->uuid}/success");
        $this->get("/api/v1/payments/fake/callback/{$payment->uuid}/success");

        $this->assertSame(
            1,
            $this->gateway->callbackCalls,
            'A refreshed return page must not re-query the gateway.',
        );

        // One event row survives; the unique index rejected the rest.
        $this->assertSame(1, PaymentWebhookEvent::query()->where('payment_id', $payment->getKey())->count());

        // And exactly one payment-status history row was written.
        $this->assertSame(
            1,
            $order->statusHistory()
                ->where('stream', \App\Models\OrderStatusHistory::STREAM_PAYMENT)
                ->where('to_status', PaymentStatus::Paid->value)
                ->count(),
        );
    }

    #[Test]
    public function a_duplicate_callback_is_deduplicated_even_when_settling_changed_the_reference(): void
    {
        /*
         * Regression.
         *
         * The dedupe key was originally built from the payment's *stored*
         * transaction reference — but settling overwrites that reference with
         * whatever the gateway finally reported. So the first callback changed
         * the very value the key was derived from, the second delivery computed
         * a different key, missed the unique index, and was processed again.
         *
         * Every gateway whose final reference differs from its session id — which
         * is most of them, Stripe included — would have got one free duplicate.
         *
         * The key now uses only the request's own identifiers, which do not move.
         */
        $order = $this->orderAwaitingPayment(10_000);
        $payment = $this->initiatedPayment($order);

        $initialReference = $payment->transaction_reference;

        // The gateway settles with a *different* reference from the one issued
        // at initiation, which is the condition that triggered the bug.
        $this->gateway->nextVerification = PaymentVerification::paid(
            gateway: 'fake',
            reference: 'txn_final_different',
            amount: 10_000,
            currency: $order->currency,
        );

        $this->gateway->resetCounts();

        $this->get("/api/v1/payments/fake/callback/{$payment->uuid}/success");

        $this->assertNotSame(
            $initialReference,
            $payment->refresh()->transaction_reference,
            'This test is only meaningful if settling changes the reference.',
        );

        $this->get("/api/v1/payments/fake/callback/{$payment->uuid}/success");

        $this->assertSame(1, $this->gateway->callbackCalls);
        $this->assertSame(1, PaymentWebhookEvent::query()->where('payment_id', $payment->getKey())->count());
    }

    #[Test]
    public function settling_an_already_settled_payment_changes_nothing(): void
    {
        $order = $this->orderAwaitingPayment(10_000);
        $payment = $this->initiatedPayment($order);

        $paid = PaymentVerification::paid('fake', 'txn_a', 10_000, $order->currency);

        $this->payments()->settle($payment, $paid);
        $paidAt = $payment->refresh()->paid_at;

        // A second, contradictory verification arrives — a webhook racing a
        // callback, or a gateway resending a stale event.
        $this->payments()->settle(
            $payment->refresh(),
            PaymentVerification::failed('fake', 'Reversed.'),
        );

        $payment->refresh();

        $this->assertSame(Payment::STATUS_PAID, $payment->status, 'A settled payment must not be un-settled.');
        $this->assertEquals($paidAt, $payment->paid_at);
    }

    #[Test]
    public function the_attempt_count_records_every_settlement_attempt(): void
    {
        // Duplicate deliveries are ordinary, not exceptional. The count makes
        // an unusual number visible without inferring it from log volume.
        $order = $this->orderAwaitingPayment(10_000);
        $payment = $this->initiatedPayment($order);

        $paid = PaymentVerification::paid('fake', 'txn_c', 10_000, $order->currency);

        $this->payments()->settle($payment, $paid);
        $this->payments()->settle($payment->refresh(), $paid);

        $this->assertSame(2, (int) $payment->refresh()->attempt_count);
    }

    /*
    |--------------------------------------------------------------------------
    | Invalid callbacks
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_callback_for_an_unknown_payment_is_redirected_not_crashed(): void
    {
        // A stale link, or someone probing. This is a customer-facing URL, so a
        // raw 404 is a poor place to land after paying.
        $this->get('/api/v1/payments/fake/callback/'.\Illuminate\Support\Str::uuid().'/success')
            ->assertRedirectContains('checkout/failed');
    }

    #[Test]
    public function a_callback_with_a_malformed_payment_id_does_not_match_the_route(): void
    {
        // The segment is constrained to a uuid shape, so a malformed value is a
        // 404 at the router rather than a lookup against an indexed column.
        $this->get('/api/v1/payments/fake/callback/not-a-uuid/success')->assertNotFound();
    }

    #[Test]
    public function a_callback_with_an_unknown_outcome_does_not_match_the_route(): void
    {
        $order = $this->orderAwaitingPayment();
        $payment = $this->initiatedPayment($order);

        $this->get("/api/v1/payments/fake/callback/{$payment->uuid}/paid")->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_verified_webhook_settles_the_payment(): void
    {
        $order = $this->orderAwaitingPayment(10_000);
        $payment = $this->initiatedPayment($order);

        $this->gateway->nextWebhookEvent = new WebhookEvent(
            gateway: 'fake',
            type: WebhookEvent::TYPE_PAYMENT_SUCCEEDED,
            reference: $payment->transaction_reference,
            orderReference: $payment->uuid,
            amount: 10_000,
            eventId: 'evt_webhook_1',
        );

        $this->gateway->nextVerification = PaymentVerification::paid(
            gateway: 'fake',
            reference: (string) $payment->transaction_reference,
            amount: 10_000,
            currency: $order->currency,
        );

        $this->postJson('/api/v1/payments/fake/webhook', ['event_id' => 'evt_webhook_1'])
            ->assertOk()
            ->assertJsonPath('data.handled', true);

        $this->assertSame(Payment::STATUS_PAID, $payment->refresh()->status);
        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
    }

    #[Test]
    public function a_signed_webhook_is_still_re_verified_with_the_gateway(): void
    {
        /*
         * Two verifications is not redundant. The signature proves the message
         * came from the gateway; the lookup proves it describes this order at
         * this amount — and for several processors the signed envelope does not
         * cover the amount at all.
         */
        $order = $this->orderAwaitingPayment(10_000);
        $payment = $this->initiatedPayment($order);

        $this->gateway->nextWebhookEvent = new WebhookEvent(
            gateway: 'fake',
            type: WebhookEvent::TYPE_PAYMENT_SUCCEEDED,
            reference: $payment->transaction_reference,
            orderReference: $payment->uuid,
            // The event *claims* the full amount...
            amount: 10_000,
            eventId: 'evt_lying',
        );

        // ...but the gateway, asked directly, reports a smaller capture.
        $this->gateway->nextVerification = PaymentVerification::paid(
            gateway: 'fake',
            reference: (string) $payment->transaction_reference,
            amount: 100,
            currency: $order->currency,
        );

        $this->postJson('/api/v1/payments/fake/webhook', ['event_id' => 'evt_lying']);

        $this->assertSame(
            Payment::STATUS_FAILED,
            $payment->refresh()->status,
            'The webhook payload must not override what the gateway reports.',
        );
    }

    #[Test]
    public function a_duplicate_webhook_is_acknowledged_but_not_reprocessed(): void
    {
        $order = $this->orderAwaitingPayment(10_000);
        $payment = $this->initiatedPayment($order);

        $event = new WebhookEvent(
            gateway: 'fake',
            type: WebhookEvent::TYPE_PAYMENT_SUCCEEDED,
            reference: $payment->transaction_reference,
            orderReference: $payment->uuid,
            amount: 10_000,
            eventId: 'evt_retry',
        );

        $this->gateway->nextWebhookEvent = $event;
        $this->gateway->nextVerification = PaymentVerification::paid(
            gateway: 'fake',
            reference: (string) $payment->transaction_reference,
            amount: 10_000,
            currency: $order->currency,
        );

        $this->postJson('/api/v1/payments/fake/webhook', [])->assertOk();

        $this->gateway->resetCounts();

        // The gateway redelivers — Stripe will do this for days until it gets a
        // 2xx it is happy with.
        $this->postJson('/api/v1/payments/fake/webhook', [])
            ->assertOk()
            ->assertJsonPath('data.reason', 'duplicate');

        $this->assertSame(0, $this->gateway->verifyCalls, 'A redelivered event must not be re-verified.');
        $this->assertSame(1, PaymentWebhookEvent::query()->where('event_id', 'evt_retry')->count());
    }

    #[Test]
    public function a_webhook_with_an_invalid_signature_is_rejected_and_recorded(): void
    {
        $this->gateway->webhookException = WebhookVerificationException::invalidSignature('fake');

        $response = $this->postJson('/api/v1/payments/fake/webhook', ['forged' => true]);

        $response->assertStatus(400);

        /*
         * The response must not say *why*. Telling a caller whether the secret
         * was wrong or the header missing is an oracle for constructing one
         * that passes.
         */
        $this->assertStringNotContainsString('signature did not match', (string) $response->getContent());

        // Recorded even though rejected — a run of these is someone probing,
        // and that pattern is only visible if the attempts are stored.
        $this->assertSame(1, PaymentWebhookEvent::query()->unverified()->count());
    }

    #[Test]
    public function an_unhandled_webhook_event_type_is_acknowledged(): void
    {
        /*
         * Gateways send far more event types than a store acts on. Answering
         * with an error for one we do not need puts the endpoint into a
         * permanent retry loop and eventually gets the webhook disabled.
         */
        $this->gateway->nextWebhookEvent = WebhookEvent::ignorable('fake', 'evt_noise');

        $this->postJson('/api/v1/payments/fake/webhook', [])
            ->assertOk()
            ->assertJsonPath('data.reason', 'unhandled_type');
    }

    #[Test]
    public function a_webhook_that_matches_no_payment_is_acknowledged(): void
    {
        // A mis-routed notification, or a test event fired from a gateway's
        // dashboard. Acknowledged so it stops retrying; recorded so it is
        // visible.
        $this->gateway->nextWebhookEvent = new WebhookEvent(
            gateway: 'fake',
            type: WebhookEvent::TYPE_PAYMENT_SUCCEEDED,
            reference: 'txn_belongs_to_nobody',
            eventId: 'evt_orphan',
        );

        $this->postJson('/api/v1/payments/fake/webhook', [])
            ->assertOk()
            ->assertJsonPath('data.reason', 'unmatched');
    }

    #[Test]
    public function a_webhook_for_an_unknown_gateway_is_a_404(): void
    {
        $this->postJson('/api/v1/payments/not_a_gateway/webhook', [])->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_abandoned_payment_is_reconciled_against_the_gateway(): void
    {
        /*
         * The case that costs a store real money: the customer paid, then
         * closed the tab before the redirect fired. Without this sweep the
         * order sits unpaid forever while the money is in the store's account.
         */
        $order = $this->orderAwaitingPayment(10_000);
        $payment = $this->initiatedPayment($order);

        $payment->forceFill(['initiated_at' => now()->subHour()])->save();

        $this->gateway->nextVerification = PaymentVerification::paid(
            gateway: 'fake',
            reference: (string) $payment->transaction_reference,
            amount: 10_000,
            currency: $order->currency,
        );

        $result = $this->payments()->reconcilePending(olderThanMinutes: 15);

        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['settled']);
        $this->assertSame(Payment::STATUS_PAID, $payment->refresh()->status);
        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
    }

    #[Test]
    public function a_recently_started_payment_is_not_reconciled(): void
    {
        // A payment started seconds ago is a shopper still typing their card
        // number, not an abandonment.
        $order = $this->orderAwaitingPayment();
        $this->initiatedPayment($order);

        $result = $this->payments()->reconcilePending(olderThanMinutes: 15);

        $this->assertSame(0, $result['checked']);
    }

    #[Test]
    public function a_pending_verification_leaves_the_payment_alone(): void
    {
        // "Not yet" is not "no". Treating it as a failure would cancel orders
        // that are about to be paid.
        $order = $this->orderAwaitingPayment();
        $payment = $this->initiatedPayment($order);

        $this->gateway->nextVerification = PaymentVerification::pending('fake', 'txn_pending');

        $this->payments()->verifyAndSettle($payment);

        $this->assertSame(Payment::STATUS_PROCESSING, $payment->refresh()->status);
        $this->assertSame(PaymentStatus::Pending, $order->refresh()->payment_status);
    }

    /*
    |--------------------------------------------------------------------------
    | Initiation guards
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_already_paid_order_cannot_start_a_second_payment(): void
    {
        // Without this a customer refreshing the payment page could be charged
        // twice.
        $user = \App\Models\User::factory()->create();
        $order = Order::factory()->forUser($user)->paid()->create();

        $this->withToken($user->createToken('t', [\App\Enums\TokenAbility::CustomerAccess->value])->plainTextToken)
            ->postJson("/api/v1/orders/{$order->uuid}/pay")
            ->assertStatus(422)
            ->assertJsonPath('code', 'ALREADY_PAID');
    }

    #[Test]
    public function a_customer_cannot_start_a_payment_for_someone_elses_order(): void
    {
        $order = Order::factory()->forUser(\App\Models\User::factory()->create())->create();
        $intruder = \App\Models\User::factory()->create();

        $this->withToken($intruder->createToken('t', [\App\Enums\TokenAbility::CustomerAccess->value])->plainTextToken)
            ->postJson("/api/v1/orders/{$order->uuid}/pay")
            ->assertStatus(403);
    }

    #[Test]
    public function initiating_reuses_an_unsettled_payment_row(): void
    {
        /*
         * A customer who reloads the payment page must not accumulate a trail
         * of abandoned payment rows against one order.
         */
        $order = $this->orderAwaitingPayment();

        $this->payments()->initiate($order);
        $this->payments()->initiate($order->refresh());

        $this->assertSame(1, $order->payments()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Status polling
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_status_endpoint_reports_stored_state_without_calling_the_gateway(): void
    {
        /*
         * A customer refreshing a page must not be able to generate outbound
         * requests to a rate-limited processor — that is a free amplification
         * vector.
         */
        $order = $this->orderAwaitingPayment();
        $payment = $this->initiatedPayment($order);

        $this->gateway->resetCounts();

        $this->getJson("/api/v1/payments/{$payment->uuid}/status")
            ->assertOk()
            ->assertJsonPath('data.status', Payment::STATUS_PROCESSING);

        $this->assertSame(0, $this->gateway->verifyCalls);
    }

    #[Test]
    public function the_status_endpoint_does_not_expose_the_gateway_response(): void
    {
        // The raw payload is reconciliation material, not something to hand to
        // a browser — its contents are not under this application's control.
        $order = $this->orderAwaitingPayment();
        $payment = $this->initiatedPayment($order);

        $body = $this->getJson("/api/v1/payments/{$payment->uuid}/status")->getContent();

        $this->assertStringNotContainsString('gateway_response', (string) $body);
    }
}
