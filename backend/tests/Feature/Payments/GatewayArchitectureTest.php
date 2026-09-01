<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\Contracts\PaymentGatewayInterface;
use App\Payments\Data\PaymentIntent;
use App\Payments\Data\PaymentVerification;
use App\Payments\Data\RefundResult;
use App\Payments\Data\WebhookEvent;
use App\Payments\Exceptions\PaymentException;
use App\Payments\Exceptions\WebhookVerificationException;
use App\Payments\Gateways\BkashGateway;
use App\Payments\Gateways\CashOnDeliveryGateway;
use App\Payments\Gateways\SslCommerzGateway;
use App\Payments\Gateways\StripeGateway;
use App\Payments\PaymentGatewayManager;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The extensibility property itself.
 *
 * The brief asks that future gateways be addable *without changing core order
 * logic*. That is not something a single feature test can demonstrate by
 * exercising a happy path — it is a structural claim, so these tests assert it
 * structurally:
 *
 * - every registered gateway satisfies the contract, checked by data provider
 *   rather than by four near-identical tests;
 * - the core files contain no reference to any concrete gateway class;
 * - a brand-new gateway, defined here in the test file and never mentioned in
 *   `config/payment.php`, works end to end.
 *
 * The last one is the real proof. If adding a gateway required touching
 * anything outside its own class, that test could not pass.
 */
final class GatewayArchitectureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{class-string<PaymentGatewayInterface>}>
     */
    public static function registeredGateways(): array
    {
        return [
            'cash on delivery' => [CashOnDeliveryGateway::class],
            'sslcommerz' => [SslCommerzGateway::class],
            'bkash' => [BkashGateway::class],
            'stripe' => [StripeGateway::class],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | The contract
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('registeredGateways')]
    public function every_gateway_implements_the_interface(string $class): void
    {
        $this->assertTrue(
            is_subclass_of($class, PaymentGatewayInterface::class),
            $class.' must implement PaymentGatewayInterface.',
        );
    }

    #[Test]
    #[DataProvider('registeredGateways')]
    public function every_gateway_reports_a_stable_identifier(string $class): void
    {
        /*
         * The identifier is stored in `payments.gateway` and is what a later
         * verification and refund are routed by. It must be a plain slug: it
         * appears in a URL segment constrained to `[a-z0-9_]+`, so a gateway
         * whose identifier contained anything else would be unreachable by its
         * own callback.
         */
        $gateway = $this->app->make($class);

        $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $gateway->identifier());
        $this->assertNotSame('', $gateway->displayName());
    }

    #[Test]
    public function every_configured_gateway_resolves_through_the_manager(): void
    {
        $manager = $this->app->make(PaymentGatewayManager::class);

        foreach (array_keys((array) config('payment.gateways')) as $identifier) {
            $gateway = $manager->gateway($identifier);

            $this->assertInstanceOf(PaymentGatewayInterface::class, $gateway);

            // The config key and the gateway's own identifier must agree, or a
            // payment stored under one would be looked up under the other.
            $this->assertSame($identifier, $gateway->identifier());
        }
    }

    #[Test]
    public function an_unknown_gateway_throws_rather_than_falling_back(): void
    {
        /*
         * A silent fallback would mean an order recorded against `stripe` being
         * processed by cash on delivery after a typo — settling money through
         * the wrong mechanism, which is worse than a loud failure.
         */
        $this->expectException(InvalidArgumentException::class);

        $this->app->make(PaymentGatewayManager::class)->gateway('no_such_gateway');
    }

    /*
    |--------------------------------------------------------------------------
    | The core knows no concrete gateway
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function core_order_logic_never_names_a_concrete_gateway(): void
    {
        /*
         * The structural claim, asserted structurally.
         *
         * If any of these files mentioned SSLCommerz or Stripe by name, adding
         * a fifth processor would mean editing them — which is precisely the
         * coupling the interface exists to prevent.
         */
        $coreFiles = [
            app_path('Services/OrderService.php'),
            app_path('Services/PaymentService.php'),
            app_path('Services/CheckoutService.php'),
            app_path('Services/RefundService.php'),
            app_path('Http/Controllers/Api/V1/PaymentController.php'),
            app_path('Http/Controllers/Api/V1/Admin/PaymentController.php'),
            app_path('Models/Order.php'),
            app_path('Models/Payment.php'),
        ];

        $forbidden = ['SslCommerzGateway', 'BkashGateway', 'StripeGateway', 'CashOnDeliveryGateway'];

        foreach ($coreFiles as $file) {
            $contents = file_get_contents($file);

            foreach ($forbidden as $class) {
                $this->assertStringNotContainsString(
                    $class,
                    (string) $contents,
                    basename($file).' must not reference the concrete gateway '.$class.'.',
                );
            }
        }
    }

    #[Test]
    public function the_gateway_registry_is_the_only_place_naming_implementations(): void
    {
        $config = file_get_contents(config_path('payment.php'));

        foreach (['SslCommerzGateway', 'BkashGateway', 'StripeGateway', 'CashOnDeliveryGateway'] as $class) {
            $this->assertStringContainsString($class, (string) $config);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Adding a gateway without touching the core
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_brand_new_gateway_works_end_to_end_without_any_core_change(): void
    {
        /*
         * The proof of the brief's requirement.
         *
         * `CryptoGateway` below is defined at the bottom of this file. It is
         * not in config, not in the container, not referenced by any
         * application class. It is registered at runtime and then drives a
         * payment all the way to a settled order — which is only possible if
         * the core genuinely depends on the interface alone.
         */
        $manager = $this->app->make(PaymentGatewayManager::class);
        $manager->extend('crypto', fn (): CryptoGateway => new CryptoGateway);

        config()->set('payment.method_gateways.cash_on_delivery', 'crypto');

        $order = Order::factory()->totals(25_000)->create([
            'payment_method' => PaymentMethod::CashOnDelivery,
        ]);

        $payments = $this->app->make(PaymentService::class);

        // Step 3 — initiate.
        $intent = $payments->initiate($order);

        $this->assertSame('crypto', $intent->gateway);
        $this->assertTrue($intent->requiresRedirect());

        $payment = $order->payments()->latest('id')->firstOrFail();
        $this->assertSame('crypto', $payment->gateway);

        // Steps 8-11 — verify and settle.
        $payments->verifyAndSettle($payment);

        $this->assertSame(Payment::STATUS_PAID, $payment->refresh()->status);
        $this->assertSame(
            PaymentStatus::Paid,
            $order->refresh()->payment_status,
            'A gateway the core has never heard of settled an order.',
        );
    }

    #[Test]
    public function a_runtime_gateway_overrides_a_configured_one_of_the_same_name(): void
    {
        // What lets a test replace a real processor with a fake, without which
        // every payment test would have to make network calls.
        $manager = $this->app->make(PaymentGatewayManager::class);

        $this->assertInstanceOf(StripeGateway::class, $manager->gateway('stripe'));

        $manager->extend('stripe', fn (): CryptoGateway => new CryptoGateway);

        $this->assertInstanceOf(CryptoGateway::class, $manager->gateway('stripe'));
    }

    /*
    |--------------------------------------------------------------------------
    | Availability
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function remote_gateways_are_unavailable_by_default(): void
    {
        /*
         * A fresh install must not offer a payment method whose credentials are
         * absent. The failure mode being defended against is an order that
         * reports itself paid and never was.
         */
        $manager = $this->app->make(PaymentGatewayManager::class);

        foreach (['sslcommerz', 'bkash', 'stripe'] as $identifier) {
            $this->assertFalse(
                $manager->gateway($identifier)->isAvailable(),
                $identifier.' must be unavailable until it is explicitly configured.',
            );
        }
    }

    #[Test]
    public function cash_on_delivery_is_always_available(): void
    {
        // Nothing to configure, so there is no state in which it is switched on
        // but unusable — which is what makes it the safe default.
        $this->assertTrue(
            $this->app->make(PaymentGatewayManager::class)->gateway('cash_on_delivery')->isAvailable(),
        );
    }

    #[Test]
    public function requesting_an_unavailable_gateway_for_a_payment_throws(): void
    {
        $this->expectException(PaymentException::class);

        $this->app->make(PaymentGatewayManager::class)->availableGateway('stripe');
    }

    /*
    |--------------------------------------------------------------------------
    | Cash on delivery's particular contract
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function cash_on_delivery_is_arranged_but_not_paid(): void
    {
        /*
         * The distinction the whole class turns on. Collapsing "arranged" into
         * "paid" would mark every cash order as settled at placement, so
         * revenue would count money nobody had collected and the unpaid queue
         * would always be empty.
         */
        $gateway = new CashOnDeliveryGateway;

        $order = Order::factory()->totals(5_000)->create();
        $payment = Payment::factory()->forOrder($order)->create();

        $intent = $gateway->initiate($order, $payment, []);

        $this->assertTrue($intent->isCompleted, 'Nothing further is required of the customer.');
        $this->assertFalse($intent->requiresRedirect(), 'There is no page to send them to.');

        $this->assertTrue(
            $gateway->verify($payment)->isPending(),
            'The money has not arrived until a human records it.',
        );
    }

    #[Test]
    public function cash_on_delivery_reports_paid_once_a_human_has_recorded_it(): void
    {
        // Safe to call repeatedly, and it must not contradict the ledger.
        $gateway = new CashOnDeliveryGateway;

        $order = Order::factory()->totals(5_000)->create();
        $payment = Payment::factory()->forOrder($order)->paid()->create();

        $this->assertTrue($gateway->verify($payment)->isPaid());
    }

    #[Test]
    public function cash_on_delivery_has_no_webhook(): void
    {
        /*
         * Throws rather than returning an ignorable event: a request claiming
         * to be a cash webhook is not a real notification from anywhere, and
         * accepting it quietly would leave an endpoint anyone can post to.
         */
        $this->expectException(WebhookVerificationException::class);

        (new CashOnDeliveryGateway)->parseWebhook(Request::create('/webhook', 'POST'));
    }

    #[Test]
    public function cash_on_delivery_does_not_support_programmatic_refunds(): void
    {
        // A cash refund is a person handing money back. RefundService reads
        // this and records the refund without calling a processor.
        $this->assertFalse((new CashOnDeliveryGateway)->supportsRefunds());
    }

    /*
    |--------------------------------------------------------------------------
    | bKash and SSLCommerz webhook posture
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function bkash_refuses_unsigned_webhooks(): void
    {
        /*
         * bKash publishes no signature scheme, so there is nothing to verify a
         * webhook against — and an unsigned webhook is an unauthenticated
         * request claiming an order was paid. Refused rather than trusted; the
         * callback and reconciliation paths cover the flow without it.
         */
        $this->expectException(WebhookVerificationException::class);

        (new BkashGateway)->parseWebhook(Request::create('/webhook', 'POST'));
    }

    #[Test]
    public function sslcommerz_rejects_an_ipn_with_no_signature(): void
    {
        config()->set('payment.sslcommerz.store_password', 'secret');

        $this->expectException(WebhookVerificationException::class);

        (new SslCommerzGateway)->parseWebhook(
            Request::create('/webhook', 'POST', ['tran_id' => 'abc', 'status' => 'VALID']),
        );
    }

    #[Test]
    public function sslcommerz_accepts_a_correctly_signed_ipn(): void
    {
        config()->set('payment.sslcommerz.store_password', 'secret');

        $payload = [
            'tran_id' => 'txn_123',
            'status' => 'VALID',
            'amount' => '100.00',
            'currency' => 'BDT',
            'val_id' => 'val_123',
        ];

        // The scheme: sort the named fields, append md5 of the store password,
        // md5 the lot.
        $fields = ['amount', 'currency', 'status', 'tran_id'];
        sort($fields);

        $parts = [];
        foreach ($fields as $field) {
            $parts[] = $field.'='.$payload[$field];
        }
        $parts[] = 'store_passwd='.md5('secret');

        $payload['verify_key'] = implode(',', $fields);
        $payload['verify_sign'] = md5(implode('&', $parts));

        $event = (new SslCommerzGateway)->parseWebhook(
            Request::create('/webhook', 'POST', $payload),
        );

        $this->assertSame(WebhookEvent::TYPE_PAYMENT_SUCCEEDED, $event->type);
        $this->assertSame('txn_123', $event->reference);
    }

    #[Test]
    public function sslcommerz_rejects_an_ipn_whose_signature_does_not_match(): void
    {
        config()->set('payment.sslcommerz.store_password', 'secret');

        $this->expectException(WebhookVerificationException::class);

        (new SslCommerzGateway)->parseWebhook(Request::create('/webhook', 'POST', [
            'tran_id' => 'txn_123',
            'status' => 'VALID',
            'amount' => '100.00',
            'currency' => 'BDT',
            'verify_key' => 'amount,currency,status,tran_id',
            'verify_sign' => str_repeat('a', 32),
        ]));
    }
}

/**
 * A gateway that exists only in this test file.
 *
 * Never registered in `config/payment.php`, never referenced by any application
 * class. Its ability to drive a payment to settlement is the demonstration that
 * a new processor needs no change to core order logic.
 */
final class CryptoGateway implements PaymentGatewayInterface
{
    public function identifier(): string
    {
        return 'crypto';
    }

    public function displayName(): string
    {
        return 'Crypto';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function isOffline(): bool
    {
        return false;
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function initiate(Order $order, Payment $payment, array $context = []): PaymentIntent
    {
        return PaymentIntent::redirect(
            gateway: $this->identifier(),
            redirectUrl: 'https://crypto.test/pay/'.$payment->uuid,
            reference: 'chain_'.$payment->uuid,
        );
    }

    public function handleCallback(Request $request, Payment $payment, string $outcome): PaymentVerification
    {
        return $this->verify($payment);
    }

    public function verify(Payment $payment): PaymentVerification
    {
        return PaymentVerification::paid(
            gateway: $this->identifier(),
            reference: (string) ($payment->transaction_reference ?? $payment->uuid),
            amount: (int) $payment->amount,
            currency: $payment->currency,
        );
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        throw WebhookVerificationException::notConfigured($this->identifier());
    }

    public function refund(Payment $payment, int $amount, ?string $reason = null): RefundResult
    {
        return RefundResult::failed($this->identifier(), 'On-chain refunds are manual.');
    }
}
