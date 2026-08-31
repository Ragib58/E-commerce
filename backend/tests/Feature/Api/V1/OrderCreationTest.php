<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Enums\SettingGroup;
use App\Enums\SettingType;
use App\Enums\TokenAbility;
use App\Models\Cart;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\OrderService;
use App\Services\SettingsService;
use App\Services\StockReservationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Order creation.
 *
 * The brief for this phase names three failures the pipeline must prevent —
 * duplicate orders, price manipulation, and stock race conditions — so the
 * assertions below are organised around those three rather than around the
 * shape of the API.
 *
 * The pricing tests matter most. An order that can be told what something costs
 * is not an order, it is an invoice the customer writes themselves, so these
 * try to submit prices through every field the checkout exposes and assert that
 * the catalog's figures survive.
 */
final class OrderCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    */

    private function shippingMethod(int $rate = 500): ShippingMethod
    {
        return ShippingMethod::factory()->create([
            'name' => 'Standard',
            'rate' => $rate,
        ]);
    }

    /**
     * A cart holding one line of a published, stocked product.
     */
    private function cartWith(Product $product, int $quantity = 1): Cart
    {
        $cart = Cart::factory()->create();

        $cart->items()->create([
            'product_id' => $product->getKey(),
            'product_variant_id' => null,
            'quantity' => $quantity,
        ]);

        return $cart;
    }

    /**
     * Walk a cart through every checkout step, leaving it ready to place.
     *
     * Uses the real service rather than the factory's shortcut, so a test that
     * then places an order has exercised the actual step guards on the way in.
     */
    private function readySession(Cart $cart, ?ShippingMethod $method = null, ?User $user = null): CheckoutSession
    {
        $checkout = app(CheckoutService::class);
        $method ??= $this->shippingMethod();

        $session = $checkout->start($cart, $user);

        $checkout->setCustomer($session, [
            'name' => 'Test Customer',
            'email' => 'customer@example.test',
            'phone' => '+15550000000',
        ]);

        $checkout->setShippingAddress($session, $this->address());
        $checkout->setBillingAddress($session, null, sameAsShipping: true);
        $checkout->setShippingMethod($session, $method->uuid);
        $checkout->setPaymentMethod($session, PaymentMethod::CashOnDelivery->value);
        $checkout->review($session);

        return $session->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function address(): array
    {
        return [
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'phone' => '+15550000000',
            'line1' => '1 Test Street',
            'city' => 'Testville',
            'state' => 'TS',
            'postal_code' => '12345',
            'country' => 'US',
        ];
    }

    private function setTaxRate(float $rate): void
    {
        app(SettingsService::class)->set(
            'business.tax_rate',
            $rate,
            SettingType::Float,
            SettingGroup::Business,
        );

        $this->app->make('cache')->flush();
    }

    private function asCustomer(User $user): self
    {
        return $this->withToken(
            $user->createToken('t', [TokenAbility::CustomerAccess->value])->plainTextToken,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Server-side pricing — no client may name a price
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_order_is_priced_from_the_catalog(): void
    {
        $product = Product::factory()->published()->create(['price' => 2_500, 'stock' => 10]);
        $cart = $this->cartWith($product, 2);
        $session = $this->readySession($cart, $this->shippingMethod(500));

        $order = app(OrderService::class)->placeFromSession($session);

        $this->assertSame(5_000, $order->subtotal, 'Subtotal must be 2 × the catalog price.');
        $this->assertSame(500, $order->shipping_total);
        $this->assertSame(5_500, $order->grand_total);

        $item = $order->items()->first();
        $this->assertSame(2_500, $item->unit_price);
        $this->assertSame(5_000, $item->line_total);
    }

    #[Test]
    public function a_submitted_price_cannot_reach_the_order(): void
    {
        $product = Product::factory()->published()->create(['price' => 10_000, 'stock' => 5]);
        $cart = $this->cartWith($product);
        $session = $this->readySession($cart, $this->shippingMethod(0));

        /*
         * Every money field the API has a name for, posted at once. None of
         * them is a parameter of any request object in the checkout, so
         * `validated()` discards them before a service ever sees them — the
         * point being that there is nothing to bypass.
         */
        $response = $this->postJson("/api/v1/checkout/{$session->token}/place", [
            'price' => 1,
            'unit_price' => 1,
            'subtotal' => 1,
            'grand_total' => 1,
            'total' => 1,
            'shipping_total' => 0,
            'tax_total' => 0,
            'discount_total' => 9_999,
        ]);

        $response->assertCreated();

        $order = Order::query()->latest('id')->firstOrFail();

        $this->assertSame(10_000, $order->subtotal);
        $this->assertSame(10_000, $order->grand_total);
        $this->assertSame(0, $order->discount_total);
    }

    #[Test]
    public function the_shipping_rate_comes_from_the_method_not_the_request(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 5]);
        $method = $this->shippingMethod(1_500);
        $cart = $this->cartWith($product);

        $checkout = app(CheckoutService::class);
        $session = $checkout->start($cart);
        $checkout->setCustomer($session, ['name' => 'A', 'email' => 'a@example.test']);
        $checkout->setShippingAddress($session, $this->address());
        $checkout->setBillingAddress($session, null, sameAsShipping: true);

        // A rate posted alongside the method id. ShippingMethodRequest has no
        // such field, so it is discarded.
        $this->putJson("/api/v1/checkout/{$session->token}/shipping-method", [
            'shipping_method' => $method->uuid,
            'rate' => 0,
            'shipping_total' => 0,
        ])->assertOk();

        $checkout->setPaymentMethod($session->refresh(), PaymentMethod::CashOnDelivery->value);
        $checkout->review($session->refresh());

        $order = app(OrderService::class)->placeFromSession($session->refresh());

        $this->assertSame(1_500, $order->shipping_total);
    }

    #[Test]
    public function a_free_shipping_threshold_is_applied_from_the_method(): void
    {
        $product = Product::factory()->published()->create(['price' => 10_000, 'stock' => 5]);
        $method = ShippingMethod::factory()->rate(500)->freeAbove(5_000)->create();

        $order = app(OrderService::class)->placeFromSession(
            $this->readySession($this->cartWith($product), $method),
        );

        $this->assertSame(0, $order->shipping_total, 'Subtotal is above the threshold, so shipping is free.');
        $this->assertSame(10_000, $order->grand_total);
    }

    #[Test]
    public function tax_is_computed_from_the_store_setting(): void
    {
        $this->setTaxRate(10);

        $product = Product::factory()->published()->create([
            'price' => 10_000,
            'stock' => 5,
            'is_taxable' => true,
        ]);

        $order = app(OrderService::class)->placeFromSession(
            $this->readySession($this->cartWith($product), $this->shippingMethod(0)),
        );

        $this->assertSame(10_000, $order->subtotal);
        $this->assertSame(1_000, $order->tax_total);
        $this->assertSame(11_000, $order->grand_total);
        $this->assertSame(10.0, $order->tax_rate, 'The rate in force is captured onto the order.');
    }

    #[Test]
    public function the_stored_totals_reconcile(): void
    {
        $this->setTaxRate(7.5);

        $product = Product::factory()->published()->create(['price' => 3_333, 'stock' => 10]);

        $order = app(OrderService::class)->placeFromSession(
            $this->readySession($this->cartWith($product, 3), $this->shippingMethod(499)),
        );

        $this->assertTrue(
            $order->totalsReconcile(),
            'subtotal − discount + tax + shipping must equal the grand total.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The snapshot — an order does not change when the catalog does
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function order_lines_snapshot_the_catalog_at_placement(): void
    {
        $product = Product::factory()->published()->create([
            'name' => 'Original Name',
            'sku' => 'ORIG-SKU',
            'price' => 4_000,
            'stock' => 10,
        ]);

        $order = app(OrderService::class)->placeFromSession(
            $this->readySession($this->cartWith($product)),
        );

        // The catalog moves on.
        $product->forceFill(['name' => 'Renamed', 'sku' => 'NEW-SKU', 'price' => 9_999])->save();

        $item = $order->fresh()->items()->first();

        $this->assertSame('Original Name', $item->product_name);
        $this->assertSame('ORIG-SKU', $item->product_sku);
        $this->assertSame(4_000, $item->unit_price, 'A price rise must not rewrite a placed order.');
    }

    #[Test]
    public function a_variant_line_snapshots_its_options(): void
    {
        $product = Product::factory()->variable()->published()->create(['stock' => 0]);
        $variant = ProductVariant::factory()->for($product)->create([
            'price' => 7_500,
            'stock' => 5,
            'is_active' => true,
        ]);

        $cart = Cart::factory()->create();
        $cart->items()->create([
            'product_id' => $product->getKey(),
            'product_variant_id' => $variant->getKey(),
            'quantity' => 1,
        ]);

        $order = app(OrderService::class)->placeFromSession($this->readySession($cart));
        $item = $order->items()->first();

        $this->assertSame($variant->getKey(), $item->product_variant_id);
        $this->assertSame(7_500, $item->unit_price);
        $this->assertNotNull($item->variant_name);
    }

    #[Test]
    public function the_addresses_are_copied_onto_the_order(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 5]);

        $order = app(OrderService::class)->placeFromSession(
            $this->readySession($this->cartWith($product)),
        );

        $this->assertNotNull($order->shippingAddress()->first());

        // "Same as shipping" produces a real second row, not a null and a flag
        // — an invoice must print a billing address without knowing how it was
        // collected.
        $billing = $order->billingAddress()->first();
        $this->assertNotNull($billing);
        $this->assertSame('1 Test Street', $billing->line1);
        $this->assertSame('US', $billing->country);
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicate prevention
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function placing_the_same_session_twice_returns_one_order(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $session = $this->readySession($this->cartWith($product, 2));

        $orders = app(OrderService::class);

        $first = $orders->placeFromSession($session);
        $second = $orders->placeFromSession($session->refresh());

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, Order::query()->count());
    }

    #[Test]
    public function an_idempotency_key_collapses_a_retried_request(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $session = $this->readySession($this->cartWith($product));

        $headers = ['Idempotency-Key' => 'order-attempt-0001'];

        $first = $this->postJson("/api/v1/checkout/{$session->token}/place", [], $headers);
        $first->assertCreated();

        $second = $this->postJson("/api/v1/checkout/{$session->token}/place", [], $headers);

        $this->assertSame(1, Order::query()->count(), 'A retry must not create a second order.');
        $this->assertSame(
            $first->json('data.order_number'),
            $second->json('data.order_number'),
        );
    }

    #[Test]
    public function a_retry_after_a_lost_response_returns_the_existing_order(): void
    {
        /*
         * The shopper's client never saw the 201 — a dropped connection, a
         * closed laptop — and retries.
         *
         * The session is completed by then, so the ordinary step guard would
         * refuse it as unusable. Answering a retry with "this checkout has
         * expired, start again" is the worst available response: the order
         * exists and will ship, and the message invites the shopper to place a
         * second one. The retry must be handed the order it already has.
         */
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $session = $this->readySession($this->cartWith($product));

        $first = $this->postJson("/api/v1/checkout/{$session->token}/place");
        $first->assertCreated();

        // No idempotency key at all — the session itself is enough.
        $retry = $this->postJson("/api/v1/checkout/{$session->token}/place");

        $retry->assertCreated();
        $this->assertSame(
            $first->json('data.order_number'),
            $retry->json('data.order_number'),
        );
        $this->assertSame(1, Order::query()->count());
    }

    #[Test]
    public function the_idempotency_key_is_uniquely_indexed(): void
    {
        /*
         * The application-level checks above catch the common cases, but the
         * guarantee rests on the database — two concurrent requests can both
         * pass a check before either inserts. This asserts the index is
         * actually there rather than trusting the migration by inspection.
         */
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $session = $this->readySession($this->cartWith($product));

        app(OrderService::class)->placeFromSession($session, idempotencyKey: 'dup-key-001');

        $this->expectException(QueryException::class);

        Order::factory()->create(['idempotency_key' => 'dup-key-001']);
    }

    #[Test]
    public function each_order_gets_a_distinct_order_number(): void
    {
        $product = Product::factory()->published()->create(['price' => 500, 'stock' => 100]);

        $numbers = [];

        for ($i = 0; $i < 5; $i++) {
            $order = app(OrderService::class)->placeFromSession(
                $this->readySession($this->cartWith($product)),
            );
            $numbers[] = $order->order_number;
        }

        $this->assertCount(5, array_unique($numbers));
    }

    #[Test]
    public function the_order_number_is_not_sequential(): void
    {
        /*
         * Guest order lookup is "order number plus email", so the number is
         * half a credential. A sequential reference would turn that endpoint
         * into an enumeration attack — see OrderNumberGenerator.
         */
        $product = Product::factory()->published()->create(['price' => 500, 'stock' => 100]);

        $first = app(OrderService::class)->placeFromSession($this->readySession($this->cartWith($product)));
        $second = app(OrderService::class)->placeFromSession($this->readySession($this->cartWith($product)));

        $randomPartOf = fn (string $number): string => (string) (explode('-', $number)[2] ?? '');

        $this->assertNotSame(
            $randomPartOf($first->order_number),
            $randomPartOf($second->order_number),
        );

        // And the alphabet excludes the characters that are ambiguous when
        // read aloud, which is what the generator promises.
        $this->assertDoesNotMatchRegularExpression(
            '/[IOU01]/',
            $randomPartOf($first->order_number),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Stock
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function placing_an_order_decrements_stock_through_the_ledger(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);

        $order = app(OrderService::class)->placeFromSession(
            $this->readySession($this->cartWith($product, 3)),
        );

        $this->assertSame(7, (int) $product->fresh()->stock);

        // Journalled, not merely decremented: InventoryService writes the level
        // and its ledger row in one transaction.
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->getKey(),
            'reason' => 'sale',
            'quantity' => -3,
            'quantity_before' => 10,
            'quantity_after' => 7,
        ]);

        $this->assertTrue((bool) $order->items()->first()->stock_was_reduced);
    }

    #[Test]
    public function an_order_cannot_be_placed_for_more_than_is_in_stock(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 2]);
        $cart = $this->cartWith($product, 2);
        $session = $this->readySession($cart);

        // Stock disappears between review and placement — another shopper got
        // there first.
        $product->forceFill(['stock' => 1])->save();

        $this->expectException(ValidationException::class);

        app(OrderService::class)->placeFromSession($session);
    }

    #[Test]
    public function a_failed_placement_leaves_no_partial_order(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 5]);
        $session = $this->readySession($this->cartWith($product, 5));

        $product->forceFill(['stock' => 1])->save();

        try {
            app(OrderService::class)->placeFromSession($session);
        } catch (ValidationException) {
            // Expected.
        }

        /*
         * The whole thing rolls back. A partial order is the one outcome worse
         * than a failed one — stock removed for an order that does not exist,
         * or lines recorded against no order.
         */
        $this->assertSame(0, Order::query()->count());
        $this->assertDatabaseCount('order_items', 0);
        $this->assertSame(1, (int) $product->fresh()->stock, 'Stock must be untouched.');
    }

    #[Test]
    public function a_digital_product_does_not_decrement_stock(): void
    {
        $product = Product::factory()->digital()->published()->create(['price' => 2_000]);

        $order = app(OrderService::class)->placeFromSession(
            $this->readySession($this->cartWith($product, 3)),
        );

        $this->assertFalse(
            (bool) $order->items()->first()->stock_was_reduced,
            'A digital line holds no stock, so cancelling it must not create inventory.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Reservations
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function reviewing_a_checkout_reserves_stock(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 5]);
        $session = $this->readySession($this->cartWith($product, 2));

        $this->assertDatabaseHas('stock_reservations', [
            'product_id' => $product->getKey(),
            'quantity' => 2,
            'checkout_session_id' => $session->getKey(),
            'status' => StockReservation::STATUS_ACTIVE,
        ]);

        // A reservation is not a decrement — the level is untouched until
        // placement.
        $this->assertSame(5, (int) $product->fresh()->stock);
    }

    #[Test]
    public function a_live_reservation_reduces_what_another_shopper_can_buy(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 3]);

        // One shopper reaches review and holds all three.
        $this->readySession($this->cartWith($product, 3));

        $reservations = app(StockReservationService::class);

        $this->assertSame(
            0,
            $reservations->availableQuantity($product->fresh()),
            'Available-to-sell is stock minus live holds.',
        );
    }

    #[Test]
    public function an_expired_reservation_stops_counting_immediately(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 3]);

        StockReservation::factory()->forStockable($product, 3)->expired()->create();

        /*
         * Not "after the sweeper runs". Availability filters on expiry in SQL,
         * so stock is never stranded in the gap between a hold lapsing and the
         * cleanup job noticing.
         */
        $this->assertSame(3, app(StockReservationService::class)->availableQuantity($product));
    }

    #[Test]
    public function placing_an_order_commits_its_reservations(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 5]);
        $session = $this->readySession($this->cartWith($product, 2));

        $order = app(OrderService::class)->placeFromSession($session);

        $this->assertDatabaseHas('stock_reservations', [
            'checkout_session_id' => $session->getKey(),
            'status' => StockReservation::STATUS_COMMITTED,
            'order_id' => $order->getKey(),
        ]);
    }

    #[Test]
    public function abandoning_a_checkout_releases_its_reservations(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 3]);
        $session = $this->readySession($this->cartWith($product, 3));

        $this->deleteJson("/api/v1/checkout/{$session->token}")->assertOk();

        $this->assertSame(
            3,
            app(StockReservationService::class)->availableQuantity($product->fresh()),
            'Releasing a hold must return the units to the sellable pool.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Guest and registered checkout
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_guest_can_place_an_order(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 5]);
        $session = $this->readySession($this->cartWith($product));

        $response = $this->postJson("/api/v1/checkout/{$session->token}/place");

        $response->assertCreated();

        $order = Order::query()->latest('id')->firstOrFail();

        $this->assertTrue((bool) $order->is_guest);
        $this->assertNull($order->user_id);
        $this->assertSame('customer@example.test', $order->customer_email);
    }

    #[Test]
    public function a_registered_customer_order_is_attached_to_their_account(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 5]);

        $cart = Cart::factory()->forUser($user)->create();
        $cart->items()->create([
            'product_id' => $product->getKey(),
            'product_variant_id' => null,
            'quantity' => 1,
        ]);

        $session = $this->readySession($cart, null, $user);

        $order = app(OrderService::class)->placeFromSession($session);

        $this->assertFalse((bool) $order->is_guest);
        $this->assertSame($user->getKey(), $order->user_id);
    }

    #[Test]
    public function the_contact_details_are_captured_rather_than_read_through_the_account(): void
    {
        $user = User::factory()->create(['name' => 'Original', 'email' => 'orig@example.test']);
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 5]);

        $cart = Cart::factory()->forUser($user)->create();
        $cart->items()->create([
            'product_id' => $product->getKey(),
            'product_variant_id' => null,
            'quantity' => 1,
        ]);

        $order = app(OrderService::class)->placeFromSession($this->readySession($cart, null, $user));

        // The customer edits their profile afterwards.
        $user->forceFill(['name' => 'Changed', 'email' => 'changed@example.test'])->save();

        $order->refresh();

        $this->assertSame(
            'customer@example.test',
            $order->customer_email,
            'The order records the address it was confirmed against.',
        );
        $this->assertNotSame('changed@example.test', $order->customer_email);
    }

    /*
    |--------------------------------------------------------------------------
    | Placement side effects
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function placing_an_order_empties_the_cart(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 5]);
        $cart = $this->cartWith($product, 2);

        app(OrderService::class)->placeFromSession($this->readySession($cart));

        $this->assertSame(0, $cart->fresh()->items()->count());

        // Emptied, not deleted: the row is the shopper's ongoing basket and the
        // guest token still has to resolve.
        $this->assertNotNull($cart->fresh());
    }

    #[Test]
    public function an_order_opens_its_audit_trail(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 5]);

        $order = app(OrderService::class)->placeFromSession(
            $this->readySession($this->cartWith($product)),
        );

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->getKey(),
            'from_status' => null,
            'to_status' => OrderStatus::Pending->value,
        ]);
    }

    #[Test]
    public function cash_on_delivery_confirms_the_order_immediately(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 5]);

        $order = app(OrderService::class)->placeFromSession(
            $this->readySession($this->cartWith($product)),
        );

        // The store has agreed to ship before being paid, so the order is ready
        // to pick — but the money has not arrived.
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
    }

    #[Test]
    public function a_payment_record_is_created(): void
    {
        $product = Product::factory()->published()->create(['price' => 2_500, 'stock' => 5]);

        $order = app(OrderService::class)->placeFromSession(
            $this->readySession($this->cartWith($product, 2)),
        );

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->getKey(),
            'method' => PaymentMethod::CashOnDelivery->value,
            'status' => Payment::STATUS_PENDING,
            'amount' => $order->grand_total,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Refusals
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_empty_cart_cannot_be_checked_out(): void
    {
        $cart = Cart::factory()->create();

        $this->expectException(ValidationException::class);

        app(CheckoutService::class)->start($cart);
    }

    #[Test]
    public function an_expired_checkout_cannot_place_an_order(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 5]);
        $session = $this->readySession($this->cartWith($product));

        $session->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->expectException(ValidationException::class);

        app(OrderService::class)->placeFromSession($session->refresh());
    }

    #[Test]
    public function an_unavailable_product_blocks_placement(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 5]);
        $session = $this->readySession($this->cartWith($product));

        // Withdrawn from sale between review and submit.
        $product->forceFill(['status' => ProductStatus::Draft])->save();

        $this->expectException(ValidationException::class);

        app(OrderService::class)->placeFromSession($session);
    }

    #[Test]
    public function a_gateway_backed_payment_method_is_refused_rather_than_silently_pending(): void
    {
        /*
         * No gateway is wired up in this phase. Accepting the choice and
         * marking the order Pending would be indistinguishable from a working
         * integration until a shopper asked why they were never charged.
         */
        $settings = app(SettingsService::class);
        $settings->set(
            PaymentMethod::Card->settingKey(),
            true,
            SettingType::Boolean,
            SettingGroup::Payment,
        );
        $this->app->make('cache')->flush();

        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 5]);
        $cart = $this->cartWith($product);

        $checkout = app(CheckoutService::class);
        $session = $checkout->start($cart);
        $checkout->setCustomer($session, ['name' => 'A', 'email' => 'a@example.test']);
        $checkout->setShippingAddress($session, $this->address());
        $checkout->setBillingAddress($session, null, sameAsShipping: true);
        $checkout->setShippingMethod($session, $this->shippingMethod()->uuid);

        $this->expectException(ValidationException::class);

        $checkout->setPaymentMethod($session, PaymentMethod::Card->value);
    }
}
