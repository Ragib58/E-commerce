<?php

declare(strict_types=1);

namespace Tests\Feature\ProductionReadiness;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RoleType;
use App\Enums\TokenAbility;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Payments\Data\PaymentVerification;
use App\Payments\PaymentGatewayManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeGateway;
use Tests\TestCase;

/**
 * The complete customer lifecycle, driven entirely over HTTP.
 *
 * Register → Login → Browse → Add to cart → Checkout → Create order →
 * Payment → Payment verification → Stock reduction → Order status → Delivery.
 *
 * ## Why this exists when each stage already has its own test file
 *
 * Every stage below is covered in isolation elsewhere. What no other test
 * asserts is that the *handoffs* work: that the token login returns is accepted
 * by the cart, that the cart the checkout reads is the one the shopper filled,
 * that the order's total equals what checkout quoted, and that stock moves
 * exactly once across the whole journey rather than at both reservation and
 * payment.
 *
 * Integration bugs live in the seams, and a suite of well-isolated unit tests
 * is precisely the shape that misses them.
 *
 * Everything goes through the public API — no service is called directly — so
 * this also proves the routes, middleware, permissions, and response envelopes
 * line up for a real client.
 */
final class CompleteOrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private FakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->app->make('cache')->flush();

        $this->gateway = new FakeGateway('fake');
        $this->app->make(PaymentGatewayManager::class)->extend('fake', fn (): FakeGateway => $this->gateway);

        /*
         * Route the cash-on-delivery method at the fake gateway, matching
         * PaymentFlowTest. A payment method is what the shopper picks; the
         * gateway behind it is configuration, and this test needs a gateway it
         * can drive deterministically without reaching a real processor.
         */
        config()->set('payment.default', 'fake');
        config()->set('payment.method_gateways.cash_on_delivery', 'fake');

        /*
         * A store with no delivery method cannot take an order at all, so the
         * test database needs one. Fixed rate rather than the factory's random
         * figure, so the total this test asserts on is predictable.
         */
        ShippingMethod::factory()->create([
            'name' => 'Standard Delivery',
            'rate' => 1_000,
            'free_above' => null,
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function address(): array
    {
        return [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'phone' => '+15550000000',
            'line1' => '1 Analytical Engine Way',
            'city' => 'London',
            'state' => 'LDN',
            'postal_code' => '12345',
            'country' => 'US',
        ];
    }

    #[Test]
    public function a_shopper_can_complete_the_entire_journey_from_registration_to_delivery(): void
    {
        /*
         * ------------------------------------------------------------------
         * 1. Register
         * ------------------------------------------------------------------
         */
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'password' => 'Str0ng!Passphrase#2026',
            'password_confirmation' => 'Str0ng!Passphrase#2026',
            'accepts_terms' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'ada@example.test']);

        $user = User::query()->where('email', 'ada@example.test')->firstOrFail();

        // The password must never be recoverable from the row that stores it.
        $this->assertNotSame('Str0ng!Passphrase#2026', $user->password);

        /*
         * ------------------------------------------------------------------
         * 2. Login
         * ------------------------------------------------------------------
         */
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.test',
            'password' => 'Str0ng!Passphrase#2026',
        ])->assertOk();

        $token = $login->json('data.token');
        $this->assertNotEmpty($token, 'Login must return a usable bearer token.');

        /*
         * ------------------------------------------------------------------
         * 3. Browse the catalog
         * ------------------------------------------------------------------
         */
        $product = Product::factory()->published()->create([
            'name' => 'Analytical Engine',
            'price' => 25_000,
            'stock' => 5,
        ]);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Analytical Engine');

        $this->getJson("/api/v1/products/{$product->slug}")
            ->assertOk()
            ->assertJsonPath('data.pricing.price', 25_000);

        /*
         * ------------------------------------------------------------------
         * 4. Add to cart
         *
         * Note what is *not* sent: any price. The request names a product and a
         * quantity; every figure below is derived server-side from the catalog.
         * ------------------------------------------------------------------
         */
        $cartResponse = $this->withToken($token)
            ->postJson('/api/v1/cart/items', [
                'product' => $product->slug,
                'quantity' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', 25_000)
            ->assertJsonPath('data.totals.subtotal', 50_000);

        $this->assertSame(50_000, $cartResponse->json('data.totals.subtotal'));

        /*
         * ------------------------------------------------------------------
         * 5. Checkout — every step, in order
         * ------------------------------------------------------------------
         */
        $start = $this->withToken($token)
            ->postJson('/api/v1/checkout')
            ->assertCreated();

        $checkoutToken = $start->json('data.token') ?? $start->headers->get('X-Checkout-Token');
        $this->assertNotEmpty($checkoutToken);

        $this->withToken($token)
            ->putJson("/api/v1/checkout/{$checkoutToken}/customer", [
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.test',
                'phone' => '+15550000000',
            ])->assertOk();

        $this->withToken($token)
            ->putJson("/api/v1/checkout/{$checkoutToken}/shipping-address", $this->address())
            ->assertOk();

        // Explicit rather than inferred: the server will not guess that billing
        // matches shipping just because no billing address was supplied.
        $this->withToken($token)
            ->putJson("/api/v1/checkout/{$checkoutToken}/billing-address", [
                'same_as_shipping' => true,
            ])->assertOk();

        $methods = $this->withToken($token)
            ->getJson("/api/v1/checkout/{$checkoutToken}/shipping-methods")
            ->assertOk();

        $shippingMethodId = $methods->json('data.0.id');
        $this->assertNotNull($shippingMethodId, 'At least one shipping method must be offered.');

        $this->withToken($token)
            ->putJson("/api/v1/checkout/{$checkoutToken}/shipping-method", [
                'shipping_method' => $shippingMethodId,
            ])->assertOk();

        $this->withToken($token)
            ->putJson("/api/v1/checkout/{$checkoutToken}/payment-method", [
                'payment_method' => 'cash_on_delivery',
            ])->assertOk();

        $review = $this->withToken($token)
            ->postJson("/api/v1/checkout/{$checkoutToken}/review")
            ->assertOk();

        $quotedTotal = $review->json('data.totals.total');
        $this->assertGreaterThanOrEqual(50_000, $quotedTotal);

        /*
         * ------------------------------------------------------------------
         * 6. Create the order
         * ------------------------------------------------------------------
         */
        $placed = $this->withToken($token)
            ->postJson("/api/v1/checkout/{$checkoutToken}/place")
            ->assertCreated();

        $orderNumber = $placed->json('data.order_number');
        $this->assertNotEmpty($orderNumber);

        $order = Order::query()->where('order_number', $orderNumber)->firstOrFail();

        // The order must charge exactly what review quoted — not a figure
        // recomputed differently, and certainly not one the client supplied.
        $this->assertSame(
            $quotedTotal,
            $order->grand_total,
            'The placed order must charge exactly what checkout quoted.',
        );

        $this->assertSame(50_000, $order->subtotal);
        $this->assertTrue($order->totalsReconcile(), 'subtotal - discount + tax + shipping must equal the total.');
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);

        /*
         * ------------------------------------------------------------------
         * 7. Payment
         *
         * Placing the order initiates the payment, so a row already exists and
         * is awaiting the gateway. It is deliberately still unpaid: creating an
         * order and receiving money are two events, and collapsing them is how
         * a store ships goods it was never paid for.
         * ------------------------------------------------------------------
         */
        $payment = $order->payments()->latest('id')->firstOrFail();

        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame($order->grand_total, $payment->amount);

        /*
         * ------------------------------------------------------------------
         * 8. Payment verification
         *
         * The shopper returns from the hosted page. That redirect travelled
         * through their machine, so it is used only to identify *which*
         * transaction to ask about — the gateway is then queried directly, and
         * its answer is what settles anything. The forged-callback test in
         * PaymentFlowTest asserts the inverse.
         * ------------------------------------------------------------------
         */
        $this->gateway->nextVerification = PaymentVerification::paid(
            gateway: 'fake',
            reference: 'txn_lifecycle_1',
            amount: $order->grand_total,
            currency: $order->currency,
        );

        $this->get("/api/v1/payments/fake/callback/{$payment->uuid}/success")
            ->assertRedirect();

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertNotNull($payment->refresh()->verified_at, 'A settlement must record when it was verified.');

        /*
         * ------------------------------------------------------------------
         * 9. Stock reduction — exactly once
         * ------------------------------------------------------------------
         */
        $product->refresh();
        $this->assertSame(
            3,
            $product->stock,
            'Two units of five must have left the shelf exactly once across the whole journey.',
        );

        /*
         * ------------------------------------------------------------------
         * 10. Order status progression, by staff
         * ------------------------------------------------------------------
         */
        $admin = Admin::factory()->withRole(RoleType::SuperAdmin)->create();
        $adminToken = $admin->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken;

        /*
         * Confirmed is deliberately absent: settling the payment already moved
         * the order there. Asserting that first, rather than trying to set it,
         * is the point — a paid order that still sat at Pending would mean the
         * settlement did not complete its side effects.
         */
        $this->assertSame(OrderStatus::Confirmed, $order->refresh()->status);

        foreach ([OrderStatus::Processing, OrderStatus::Packed] as $status) {
            $this->withToken($adminToken)
                ->patchJson("/api/v1/admin/orders/{$order->uuid}/status", [
                    'status' => $status->value,
                ])->assertOk();
        }

        // Shipping carries the courier details the customer will track with.
        $this->withToken($adminToken)
            ->patchJson("/api/v1/admin/orders/{$order->uuid}/status", [
                'status' => OrderStatus::Shipped->value,
                'courier_name' => 'Test Courier',
                'tracking_number' => 'TRK-123456',
                'tracking_url' => 'https://courier.test/TRK-123456',
            ])->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Shipped, $order->status);
        $this->assertSame('TRK-123456', $order->tracking_number);
        $this->assertNotNull($order->dispatched_at);

        /*
         * ------------------------------------------------------------------
         * 11. Delivery
         * ------------------------------------------------------------------
         */
        $this->withToken($adminToken)
            ->patchJson("/api/v1/admin/orders/{$order->uuid}/status", [
                'status' => OrderStatus::Delivered->value,
            ])->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertNotNull($order->delivered_at);

        // And the customer can see the finished order, with its tracking.
        $this->withToken($token)
            ->getJson("/api/v1/orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Delivered->value)
            ->assertJsonPath('data.tracking.number', 'TRK-123456');

        // The whole journey left one audit trail, not a silent state change.
        $this->assertGreaterThan(0, $order->statusHistory()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | The same journey, refused at each point a client tries to cheat
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function stock_is_never_reduced_twice_by_a_repeated_verification(): void
    {
        [$token, $order, $product, $paymentUuid] = $this->journeyToPayment();

        $this->gateway->nextVerification = PaymentVerification::paid(
            gateway: 'fake',
            reference: 'txn_replay_1',
            amount: $order->grand_total,
            currency: $order->currency,
        );

        $this->get("/api/v1/payments/fake/callback/{$paymentUuid}/success")->assertRedirect();
        $this->assertSame(3, $product->refresh()->stock);

        // A gateway retry, a refreshed tab, a double-clicked button — all of
        // which happen constantly in production.
        $this->get("/api/v1/payments/fake/callback/{$paymentUuid}/success")->assertRedirect();

        $this->assertSame(
            3,
            $product->refresh()->stock,
            'A repeated verification must not decrement stock a second time.',
        );

        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
    }

    /**
     * Drive the journey up to an initiated, unverified payment.
     *
     * @return array{0: string, 1: Order, 2: Product, 3: string}
     */
    private function journeyToPayment(): array
    {
        $user = User::factory()->create(['email' => 'buyer@example.test']);
        $token = $user->createToken('t', [TokenAbility::CustomerAccess->value])->plainTextToken;

        $product = Product::factory()->published()->create(['price' => 25_000, 'stock' => 5]);

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', ['product' => $product->slug, 'quantity' => 2])
            ->assertCreated();

        $start = $this->withToken($token)->postJson('/api/v1/checkout')->assertCreated();
        $checkoutToken = $start->json('data.token') ?? $start->headers->get('X-Checkout-Token');

        $this->withToken($token)->putJson("/api/v1/checkout/{$checkoutToken}/customer", [
            'name' => 'Buyer',
            'email' => 'buyer@example.test',
        ])->assertOk();

        $this->withToken($token)
            ->putJson("/api/v1/checkout/{$checkoutToken}/shipping-address", $this->address())
            ->assertOk();

        $this->withToken($token)->putJson("/api/v1/checkout/{$checkoutToken}/billing-address", [
            'same_as_shipping' => true,
        ])->assertOk();

        $methods = $this->withToken($token)
            ->getJson("/api/v1/checkout/{$checkoutToken}/shipping-methods")->assertOk();

        $this->withToken($token)->putJson("/api/v1/checkout/{$checkoutToken}/shipping-method", [
            'shipping_method' => $methods->json('data.0.id'),
        ])->assertOk();

        $this->withToken($token)->putJson("/api/v1/checkout/{$checkoutToken}/payment-method", [
            'payment_method' => 'cash_on_delivery',
        ])->assertOk();

        $this->withToken($token)->postJson("/api/v1/checkout/{$checkoutToken}/review")->assertOk();

        $placed = $this->withToken($token)
            ->postJson("/api/v1/checkout/{$checkoutToken}/place")->assertCreated();

        $order = Order::query()
            ->where('order_number', $placed->json('data.order_number'))
            ->firstOrFail();

        // Placing the order already initiated the payment.
        $payment = $order->payments()->latest('id')->firstOrFail();

        return [$token, $order, $product, $payment->uuid];
    }
}
