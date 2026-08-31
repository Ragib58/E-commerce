<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\CheckoutStep;
use App\Enums\PaymentMethod;
use App\Enums\TokenAbility;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The seven-step checkout.
 *
 * The load-bearing assertion is that the *server* owns the step sequence. If a
 * client can post straight to "place order" having skipped shipping selection,
 * the order is created with a null shipping cost — so these tests try to skip,
 * reorder, and jump ahead through the HTTP surface rather than through the
 * service, because the HTTP surface is what an attacker actually has.
 */
final class CheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();
    }

    private function cartWithProduct(int $price = 2_000, int $stock = 10, int $quantity = 1): Cart
    {
        $product = Product::factory()->published()->create(['price' => $price, 'stock' => $stock]);

        $cart = Cart::factory()->create();
        $cart->items()->create([
            'product_id' => $product->getKey(),
            'product_variant_id' => null,
            'quantity' => $quantity,
        ]);

        return $cart;
    }

    /**
     * @return array<string, mixed>
     */
    private function address(string $country = 'US'): array
    {
        return [
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'phone' => '+15550000000',
            'line1' => '1 Test Street',
            'city' => 'Testville',
            'state' => 'TS',
            'postal_code' => '12345',
            'country' => $country,
        ];
    }

    private function asCustomer(User $user): self
    {
        return $this->withToken(
            $user->createToken('t', [TokenAbility::CustomerAccess->value])->plainTextToken,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Starting
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_guest_can_start_a_checkout(): void
    {
        $cart = $this->cartWithProduct();

        $response = $this->withHeaders(['X-Cart-Token' => $cart->token])
            ->postJson('/api/v1/checkout');

        $response->assertCreated()
            ->assertJsonPath('data.current_step', CheckoutStep::Customer->value)
            ->assertJsonPath('data.is_guest', true);

        $this->assertNotNull($response->headers->get('X-Checkout-Token'));
    }

    #[Test]
    public function starting_twice_resumes_the_same_checkout(): void
    {
        /*
         * Idempotent on purpose: a client calling this on every page load must
         * not discard the address the shopper already typed.
         */
        $cart = $this->cartWithProduct();
        $checkout = app(CheckoutService::class);

        $first = $checkout->start($cart);
        $checkout->setCustomer($first, ['name' => 'A', 'email' => 'a@example.test']);

        $second = $checkout->start($cart);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame('a@example.test', $second->get('customer.email'));
    }

    #[Test]
    public function a_signed_in_customers_details_are_prefilled(): void
    {
        $user = User::factory()->create(['name' => 'Member', 'email' => 'member@example.test']);
        $cart = Cart::factory()->forUser($user)->create();
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 5]);
        $cart->items()->create([
            'product_id' => $product->getKey(),
            'product_variant_id' => null,
            'quantity' => 1,
        ]);

        $session = app(CheckoutService::class)->start($cart, $user);

        $this->assertSame('Member', $session->get('customer.name'));
        $this->assertSame('member@example.test', $session->get('customer.email'));
    }

    #[Test]
    public function a_guest_who_signs_in_mid_checkout_keeps_their_session(): void
    {
        /*
         * Signing in to use a saved card is a normal thing to do at step five.
         * Starting fresh and losing the address entered at step two is how a
         * checkout gets abandoned.
         */
        $cart = $this->cartWithProduct();
        $checkout = app(CheckoutService::class);

        $session = $checkout->start($cart);
        $checkout->setCustomer($session, ['name' => 'Guest', 'email' => 'guest@example.test']);
        $checkout->setShippingAddress($session, $this->address());

        $user = User::factory()->create();
        $cart->forceFill(['user_id' => $user->getKey()])->save();

        $resumed = $checkout->start($cart->refresh(), $user);

        $this->assertSame($session->getKey(), $resumed->getKey());
        $this->assertSame($user->getKey(), $resumed->user_id);
        $this->assertNotNull($resumed->get('shipping_address'), 'The address must survive signing in.');
    }

    /*
    |--------------------------------------------------------------------------
    | The step sequence is enforced server-side
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_shipping_address_cannot_be_set_before_customer_details(): void
    {
        $cart = $this->cartWithProduct();
        $session = app(CheckoutService::class)->start($cart);

        $response = $this->putJson(
            "/api/v1/checkout/{$session->token}/shipping-address",
            $this->address(),
        );

        $response->assertStatus(422)
            // The error names the step that must be completed first, so a
            // client can navigate there rather than only knowing it failed.
            ->assertJsonPath('errors.required_step.0', CheckoutStep::Customer->value);
    }

    #[Test]
    public function an_order_cannot_be_placed_by_skipping_straight_to_the_end(): void
    {
        /*
         * The attack the server-side sequence exists to stop. Without it the
         * order is created with no shipping method and a null shipping cost.
         */
        $cart = $this->cartWithProduct();
        $session = app(CheckoutService::class)->start($cart);

        $this->postJson("/api/v1/checkout/{$session->token}/place")
            ->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function a_shipping_method_cannot_be_chosen_before_an_address(): void
    {
        $cart = $this->cartWithProduct();
        $method = ShippingMethod::factory()->create();
        $checkout = app(CheckoutService::class);

        $session = $checkout->start($cart);
        $checkout->setCustomer($session, ['name' => 'A', 'email' => 'a@example.test']);

        $this->putJson("/api/v1/checkout/{$session->token}/shipping-method", [
            'shipping_method' => $method->uuid,
        ])->assertStatus(422);
    }

    #[Test]
    public function review_cannot_be_reached_without_a_payment_method(): void
    {
        $cart = $this->cartWithProduct();
        $checkout = app(CheckoutService::class);

        $session = $checkout->start($cart);
        $checkout->setCustomer($session, ['name' => 'A', 'email' => 'a@example.test']);
        $checkout->setShippingAddress($session, $this->address());
        $checkout->setBillingAddress($session, null, sameAsShipping: true);
        $checkout->setShippingMethod($session, ShippingMethod::factory()->create()->uuid);

        $this->postJson("/api/v1/checkout/{$session->token}/review")
            ->assertStatus(422);
    }

    #[Test]
    public function the_progress_indicator_reflects_what_is_actually_stored(): void
    {
        $cart = $this->cartWithProduct();
        $checkout = app(CheckoutService::class);

        $session = $checkout->start($cart);
        $checkout->setCustomer($session, ['name' => 'A', 'email' => 'a@example.test']);

        $response = $this->getJson("/api/v1/checkout/{$session->refresh()->token}");

        $response->assertOk()
            ->assertJsonPath('data.current_step', CheckoutStep::ShippingAddress->value)
            ->assertJsonPath('data.progress.0.is_complete', true)
            ->assertJsonPath('data.progress.1.is_complete', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Invalidation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function changing_country_clears_the_chosen_shipping_method(): void
    {
        /*
         * The new country may not be served. Carrying the choice forward would
         * price the order with a method that is no longer offered.
         */
        $cart = $this->cartWithProduct();
        $checkout = app(CheckoutService::class);

        $session = $checkout->start($cart);
        $checkout->setCustomer($session, ['name' => 'A', 'email' => 'a@example.test']);
        $checkout->setShippingAddress($session, $this->address('US'));
        $checkout->setBillingAddress($session, null, sameAsShipping: true);
        $checkout->setShippingMethod($session, ShippingMethod::factory()->create()->uuid);

        $this->assertNotNull($session->refresh()->get('shipping_method_id'));

        $checkout->setShippingAddress($session->refresh(), $this->address('GB'));

        $this->assertNull(
            $session->refresh()->get('shipping_method_id'),
            'Crossing a border must re-open the delivery choice.',
        );
    }

    #[Test]
    public function moving_within_one_country_keeps_the_shipping_method(): void
    {
        // Moving house does not change which carriers serve the country, and
        // clearing a valid choice for that sends the shopper back for nothing.
        $cart = $this->cartWithProduct();
        $checkout = app(CheckoutService::class);

        $session = $checkout->start($cart);
        $checkout->setCustomer($session, ['name' => 'A', 'email' => 'a@example.test']);
        $checkout->setShippingAddress($session, $this->address('US'));
        $checkout->setBillingAddress($session, null, sameAsShipping: true);
        $checkout->setShippingMethod($session, ShippingMethod::factory()->create()->uuid);

        $moved = array_merge($this->address('US'), ['line1' => '99 Другая Street', 'city' => 'Elsewhere']);
        $checkout->setShippingAddress($session->refresh(), $moved);

        $this->assertNotNull($session->refresh()->get('shipping_method_id'));
    }

    #[Test]
    public function changing_a_priced_input_clears_the_review_acknowledgement(): void
    {
        /*
         * "You agreed to this total" is only true of a total that was actually
         * shown. A stale acknowledgement would let a shopper be charged a
         * figure they never saw.
         */
        $cart = $this->cartWithProduct();
        $checkout = app(CheckoutService::class);
        $cheap = ShippingMethod::factory()->rate(500)->create();
        $dear = ShippingMethod::factory()->rate(2_000)->create();

        $session = $checkout->start($cart);
        $checkout->setCustomer($session, ['name' => 'A', 'email' => 'a@example.test']);
        $checkout->setShippingAddress($session, $this->address());
        $checkout->setBillingAddress($session, null, sameAsShipping: true);
        $checkout->setShippingMethod($session, $cheap->uuid);
        $checkout->setPaymentMethod($session, PaymentMethod::CashOnDelivery->value);
        $checkout->review($session);

        $this->assertTrue($session->refresh()->isReadyToPlace());

        $checkout->setShippingMethod($session->refresh(), $dear->uuid);

        $this->assertFalse(
            $session->refresh()->isReadyToPlace(),
            'A changed shipping cost must send the shopper back to review.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Shipping methods
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function only_available_methods_are_offered(): void
    {
        $cart = $this->cartWithProduct(price: 2_000);
        $checkout = app(CheckoutService::class);

        ShippingMethod::factory()->create(['name' => 'Everywhere']);
        ShippingMethod::factory()->forCountries(['GB'])->create(['name' => 'UK only']);
        ShippingMethod::factory()->inactive()->create(['name' => 'Retired']);

        $session = $checkout->start($cart);
        $checkout->setCustomer($session, ['name' => 'A', 'email' => 'a@example.test']);
        $checkout->setShippingAddress($session, $this->address('US'));

        $names = collect($checkout->availableShippingMethods($session->refresh()))
            ->pluck('name')
            ->all();

        $this->assertContains('Everywhere', $names);
        $this->assertNotContains('UK only', $names, 'A method that does not serve the destination is not offered.');
        $this->assertNotContains('Retired', $names);
    }

    #[Test]
    public function a_method_that_was_not_offered_cannot_be_chosen_by_posting_its_id(): void
    {
        $cart = $this->cartWithProduct(price: 2_000);
        $ukOnly = ShippingMethod::factory()->forCountries(['GB'])->create();
        $checkout = app(CheckoutService::class);

        $session = $checkout->start($cart);
        $checkout->setCustomer($session, ['name' => 'A', 'email' => 'a@example.test']);
        $checkout->setShippingAddress($session, $this->address('US'));
        $checkout->setBillingAddress($session, null, sameAsShipping: true);

        $this->expectException(ValidationException::class);

        $checkout->setShippingMethod($session->refresh(), $ukOnly->uuid);
    }

    #[Test]
    public function the_free_shipping_threshold_shows_in_the_quote(): void
    {
        $cart = $this->cartWithProduct(price: 10_000);
        ShippingMethod::factory()->rate(500)->freeAbove(5_000)->create();
        $checkout = app(CheckoutService::class);

        $session = $checkout->start($cart);
        $checkout->setCustomer($session, ['name' => 'A', 'email' => 'a@example.test']);
        $checkout->setShippingAddress($session, $this->address());

        $offered = $checkout->availableShippingMethods($session->refresh())[0];

        $this->assertSame(0, $offered['rate']);
        $this->assertTrue($offered['is_free']);
        $this->assertSame(500, $offered['list_rate'], 'The list price is still reported, for a "was" line.');
    }

    /*
    |--------------------------------------------------------------------------
    | Session ownership
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function another_customers_checkout_cannot_be_resumed(): void
    {
        /*
         * A leaked token would otherwise expose the name, address, and phone
         * number in the session.
         */
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $cart = Cart::factory()->forUser($owner)->create();
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 5]);
        $cart->items()->create([
            'product_id' => $product->getKey(),
            'product_variant_id' => null,
            'quantity' => 1,
        ]);

        $session = app(CheckoutService::class)->start($cart, $owner);

        $this->asCustomer($intruder)
            ->getJson("/api/v1/checkout/{$session->token}")
            ->assertStatus(422);
    }

    #[Test]
    public function a_guest_cannot_resume_a_claimed_checkout(): void
    {
        $owner = User::factory()->create();
        $cart = Cart::factory()->forUser($owner)->create();
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 5]);
        $cart->items()->create([
            'product_id' => $product->getKey(),
            'product_variant_id' => null,
            'quantity' => 1,
        ]);

        $session = app(CheckoutService::class)->start($cart, $owner);

        $this->getJson("/api/v1/checkout/{$session->token}")->assertStatus(422);
    }

    #[Test]
    public function an_unknown_token_and_a_stranger_token_are_indistinguishable(): void
    {
        // Distinguishing them would confirm that a token is valid but belongs
        // to someone else.
        $owner = User::factory()->create();
        $cart = Cart::factory()->forUser($owner)->create();
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 5]);
        $cart->items()->create([
            'product_id' => $product->getKey(),
            'product_variant_id' => null,
            'quantity' => 1,
        ]);

        $session = app(CheckoutService::class)->start($cart, $owner);

        $strangers = $this->getJson("/api/v1/checkout/{$session->token}");
        $unknown = $this->getJson('/api/v1/checkout/'.str_repeat('a', 64));

        $this->assertSame($strangers->status(), $unknown->status());
        $this->assertSame(
            $strangers->json('errors.checkout.0'),
            $unknown->json('errors.checkout.0'),
        );
    }

    #[Test]
    public function an_expired_session_cannot_be_advanced(): void
    {
        $cart = $this->cartWithProduct();
        $session = app(CheckoutService::class)->start($cart);
        $session->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->putJson("/api/v1/checkout/{$session->token}/customer", [
            'name' => 'A',
            'email' => 'a@example.test',
        ])->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function customer_details_are_validated(): void
    {
        $cart = $this->cartWithProduct();
        $session = app(CheckoutService::class)->start($cart);

        $this->putJson("/api/v1/checkout/{$session->token}/customer", [
            'name' => '',
            'email' => 'not-an-email',
        ])->assertStatus(422)->assertJsonValidationErrors(['name', 'email']);
    }

    #[Test]
    public function an_address_requires_only_the_parts_every_country_has(): void
    {
        /*
         * A schema modelled on one country's postal system rejects valid
         * addresses elsewhere, and every rejection is a lost sale for a
         * formatting opinion. State and postcode are optional.
         */
        $cart = $this->cartWithProduct();
        $checkout = app(CheckoutService::class);
        $session = $checkout->start($cart);
        $checkout->setCustomer($session, ['name' => 'A', 'email' => 'a@example.test']);

        $this->putJson("/api/v1/checkout/{$session->refresh()->token}/shipping-address", [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'line1' => '12 Some Road',
            'city' => 'Freetown',
            'country' => 'SL',
        ])->assertOk();
    }

    #[Test]
    public function a_billing_address_is_required_unless_it_matches_shipping(): void
    {
        $cart = $this->cartWithProduct();
        $checkout = app(CheckoutService::class);
        $session = $checkout->start($cart);
        $checkout->setCustomer($session, ['name' => 'A', 'email' => 'a@example.test']);
        $checkout->setShippingAddress($session, $this->address());

        $this->putJson("/api/v1/checkout/{$session->refresh()->token}/billing-address", [
            'same_as_shipping' => false,
        ])->assertStatus(422);
    }

    #[Test]
    public function the_country_is_normalised_to_upper_case(): void
    {
        // A method serving "GB" must not be refused because a client sent "gb".
        $cart = $this->cartWithProduct();
        $checkout = app(CheckoutService::class);
        $session = $checkout->start($cart);
        $checkout->setCustomer($session, ['name' => 'A', 'email' => 'a@example.test']);

        $checkout->setShippingAddress($session, array_merge($this->address(), ['country' => 'gb']));

        $this->assertSame('GB', $session->refresh()->get('shipping_address.country'));
    }

    /*
    |--------------------------------------------------------------------------
    | The checkout stores no money
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_session_persists_no_totals(): void
    {
        /*
         * A total persisted at step four and trusted at step seven is a
         * three-step window in which the catalog can move, and a writable
         * surface a crafted request can aim at.
         */
        $cart = $this->cartWithProduct(price: 3_000);
        $checkout = app(CheckoutService::class);
        $method = ShippingMethod::factory()->rate(500)->create();

        $session = $checkout->start($cart);
        $checkout->setCustomer($session, ['name' => 'A', 'email' => 'a@example.test']);
        $checkout->setShippingAddress($session, $this->address());
        $checkout->setBillingAddress($session, null, sameAsShipping: true);
        $checkout->setShippingMethod($session, $method->uuid);

        $stored = $session->refresh()->data;

        foreach (['subtotal', 'total', 'grand_total', 'tax', 'shipping', 'shipping_total'] as $moneyKey) {
            $this->assertArrayNotHasKey($moneyKey, $stored);
        }

        // The method's *id* is what is stored; the rate is looked up.
        $this->assertSame($method->getKey(), $stored['shipping_method_id']);
    }

    #[Test]
    public function the_total_is_recomputed_when_the_catalog_moves(): void
    {
        $product = Product::factory()->published()->create(['price' => 2_000, 'stock' => 10]);
        $cart = Cart::factory()->create();
        $cart->items()->create([
            'product_id' => $product->getKey(),
            'product_variant_id' => null,
            'quantity' => 1,
        ]);

        $checkout = app(CheckoutService::class);
        $session = $checkout->start($cart);
        $checkout->setCustomer($session, ['name' => 'A', 'email' => 'a@example.test']);
        $checkout->setShippingAddress($session, $this->address());

        $this->assertSame(2_000, $checkout->summarise($session->refresh())['totals']['subtotal']);

        $product->forceFill(['price' => 3_500])->save();

        $this->assertSame(
            3_500,
            $checkout->summarise($session->refresh())['totals']['subtotal'],
            'Every figure is derived on read, never read back from the session.',
        );
    }
}
