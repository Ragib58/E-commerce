<?php

declare(strict_types=1);

namespace Tests\Feature\ProductionReadiness;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TokenAbility;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The trust boundary: what a client says versus what the server believes.
 *
 * The brief asks for verification that the frontend cannot manipulate prices,
 * discounts, stock, or payment status. Each of those gets a test below that
 * *actually attempts the manipulation* through the real API and asserts the
 * server ignored it — rather than asserting the happy path and inferring
 * safety from its absence.
 *
 * ## Why the strongest guarantees here are structural
 *
 * Two of these cannot fail by accident, and that is deliberate:
 *
 *  - **`cart_items` has no price column.** There is nowhere for a submitted
 *    price to be stored, so "the cart trusted a client price" is not a bug that
 *    can be introduced by forgetting a check — it would require a migration.
 *  - **`Order::payment_status` throws on direct assignment.** The model guard
 *    fires on any write outside OrderService, and survives `forceFill`, so a
 *    new endpoint cannot quietly mark an order paid.
 *
 * Validation that *rejects* a bad price is weaker than a design with no field
 * to reject: the check can be skipped, mis-ordered, or forgotten on the next
 * endpoint. These tests assert the strong version.
 */
final class ClientTrustBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();
    }

    private function customer(): array
    {
        $user = User::factory()->create();

        return [$user, $user->createToken('t', [TokenAbility::CustomerAccess->value])->plainTextToken];
    }

    /*
    |--------------------------------------------------------------------------
    | Prices
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_submitted_price_is_ignored_on_every_field_it_could_arrive_through(): void
    {
        $product = Product::factory()->published()->create(['price' => 50_000, 'stock' => 10]);
        [, $token] = $this->customer();

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', [
                'product' => $product->slug,
                'quantity' => 1,

                // Every field name a naive implementation might read.
                'price' => 1,
                'unit_price' => 1,
                'effective_price' => 1,
                'discount_price' => 1,
                'line_total' => 1,
                'subtotal' => 1,
                'total' => 1,
                'grand_total' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', 50_000)
            ->assertJsonPath('data.totals.subtotal', 50_000)
            ->assertJsonPath('data.totals.total', 50_000);
    }

    #[Test]
    public function the_cart_has_nowhere_to_store_a_client_price(): void
    {
        /*
         * The structural guarantee, asserted directly against the schema.
         *
         * If this ever fails, a migration added a price column to cart_items —
         * at which point a submitted price becomes storable and every test
         * above becomes a check that can be bypassed rather than a fact.
         */
        foreach (['price', 'unit_price', 'line_total', 'subtotal', 'total'] as $column) {
            $this->assertFalse(
                \Schema::hasColumn('cart_items', $column),
                "cart_items must not have a `{$column}` column — prices are derived, never stored.",
            );
        }
    }

    #[Test]
    public function a_price_edited_after_adding_to_cart_is_reflected_not_frozen(): void
    {
        $product = Product::factory()->published()->create(['price' => 10_000, 'stock' => 10]);
        [, $token] = $this->customer();

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', ['product' => $product->slug])
            ->assertCreated();

        $product->forceFill(['price' => 12_000])->save();

        // Derived on read, so there is no stale stored figure to exploit by
        // adding to cart before a price rise.
        $this->withToken($token)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.items.0.unit_price', 12_000);
    }

    /*
    |--------------------------------------------------------------------------
    | Discounts
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_client_cannot_invent_a_discount(): void
    {
        $product = Product::factory()->published()->create(['price' => 50_000, 'stock' => 10]);
        [, $token] = $this->customer();

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', [
                'product' => $product->slug,
                'discount' => 45_000,
                'discount_total' => 45_000,
                'coupon_discount' => 45_000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.totals.subtotal', 50_000)
            ->assertJsonPath('data.totals.total', 50_000);
    }

    #[Test]
    public function an_unknown_coupon_is_rejected_rather_than_silently_discounting(): void
    {
        $product = Product::factory()->published()->create(['price' => 50_000, 'stock' => 10]);
        [, $token] = $this->customer();

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', ['product' => $product->slug])
            ->assertCreated();

        $this->withToken($token)
            ->postJson('/api/v1/cart/coupon', ['coupon_code' => 'FREESTUFF'])
            ->assertUnprocessable();

        $this->withToken($token)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.totals.total', 50_000);
    }

    /*
    |--------------------------------------------------------------------------
    | Stock
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_client_cannot_set_stock_through_the_cart(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 2]);
        [, $token] = $this->customer();

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', [
                'product' => $product->slug,
                'quantity' => 1,
                'stock' => 9_999,
                'available_stock' => 9_999,
            ])
            ->assertCreated();

        $this->assertSame(2, $product->refresh()->stock, 'Stock is warehouse state, not a request field.');
    }

    #[Test]
    public function ordering_more_than_exists_is_refused(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 2]);
        [, $token] = $this->customer();

        $this->withToken($token)
            ->postJson('/api/v1/cart/items', ['product' => $product->slug, 'quantity' => 50])
            ->assertUnprocessable();
    }

    #[Test]
    public function stock_cannot_be_changed_without_the_inventory_permission(): void
    {
        $product = Product::factory()->published()->create(['stock' => 5]);
        [, $token] = $this->customer();

        // A customer token against an admin route.
        $this->withToken($token)
            ->postJson("/api/v1/admin/products/{$product->uuid}/stock", [
                'quantity' => 500,
                'reason' => 'correction',
            ])
            ->assertUnauthorized();

        $this->assertSame(5, $product->refresh()->stock);
    }

    /*
    |--------------------------------------------------------------------------
    | Payment status
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function payment_status_cannot_be_assigned_outside_the_order_service(): void
    {
        $order = Order::factory()->create(['payment_status' => PaymentStatus::Pending]);

        /*
         * The guard that makes "an order can be told it was paid" impossible
         * rather than merely unimplemented. forceFill bypasses $fillable but
         * not model events, so this still throws.
         */
        $this->expectException(\LogicException::class);

        $order->forceFill(['payment_status' => PaymentStatus::Paid])->save();
    }

    #[Test]
    public function order_status_cannot_be_assigned_outside_the_order_service(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);

        $this->expectException(\LogicException::class);

        $order->forceFill(['status' => OrderStatus::Delivered])->save();
    }

    #[Test]
    public function there_is_no_customer_endpoint_that_marks_an_order_paid(): void
    {
        $order = Order::factory()->create(['payment_status' => PaymentStatus::Pending]);
        [, $token] = $this->customer();

        /*
         * Every plausible shape a client might try. None of these routes exist
         * for a customer — which is the assertion: the surface is absent, not
         * merely guarded.
         */
        $attempts = [
            ['patch', "/api/v1/orders/{$order->uuid}", ['payment_status' => 'paid']],
            ['patch', "/api/v1/orders/{$order->uuid}/payment-status", ['payment_status' => 'paid']],
            ['post', "/api/v1/orders/{$order->uuid}/pay", []],
            ['patch', "/api/v1/admin/orders/{$order->uuid}/status", ['status' => 'delivered']],
        ];

        foreach ($attempts as [$verb, $uri, $payload]) {
            $response = $this->withToken($token)->json(strtoupper($verb), $uri, $payload);

            $this->assertContains(
                $response->getStatusCode(),
                [401, 403, 404, 405, 422],
                "{$verb} {$uri} must not succeed for a customer.",
            );
        }

        $order->refresh();
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame(OrderStatus::Pending, $order->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Cross-customer isolation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function one_customer_cannot_read_anothers_order(): void
    {
        $owner = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $owner->getKey()]);

        [, $intruderToken] = $this->customer();

        $response = $this->withToken($intruderToken)->getJson("/api/v1/orders/{$order->uuid}");

        $this->assertContains(
            $response->getStatusCode(),
            [403, 404],
            'Another customer\'s order must not be readable.',
        );
    }

    #[Test]
    public function an_admin_token_never_resolves_to_a_customers_cart(): void
    {
        /*
         * The cart routes carry no `auth:sanctum` guard on purpose — guests
         * must be able to shop, so the same endpoints serve both. That makes
         * "who is this?" a question the guard's *provider* answers rather than
         * the middleware.
         *
         * The risk it creates: `carts.user_id` is a foreign key into `users`,
         * and `admins` is a separate table with its own id sequence. If an
         * admin token resolved to a principal, admin #7 would read and write
         * the cart of customer #7 — a cross-tenant leak in both directions.
         *
         * It does not, because the default `sanctum` guard is bound to the
         * `users` provider: an admin token's `tokenable_type` cannot match, so
         * `$request->user()` is null and the request takes the guest path.
         * Staff have no shopping cart, which is the honest answer.
         *
         * Asserted here because that safety comes from a config binding two
         * files away, not from anything visible at the route.
         */
        $customer = User::factory()->create();
        $product = Product::factory()->published()->create(['price' => 7_500, 'stock' => 5]);

        $this->withToken($customer->createToken('t', [TokenAbility::CustomerAccess->value])->plainTextToken)
            ->postJson('/api/v1/cart/items', ['product' => $product->slug, 'quantity' => 2])
            ->assertCreated();

        $admin = Admin::factory()->create();

        // Deliberately not asserting 401: an unguarded route answering a guest
        // is correct. What matters is *whose* cart comes back.
        $this->withToken($admin->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.item_count', 0)
            ->assertJsonPath('data.totals.subtotal', 0);
    }

    #[Test]
    public function an_admin_token_is_refused_on_a_guarded_customer_route(): void
    {
        // Where a route *is* guarded, the wrong principal is a flat refusal
        // rather than a silent downgrade to guest.
        $admin = Admin::factory()->create();

        $this->withToken($admin->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken)
            ->getJson('/api/v1/wishlist')
            ->assertUnauthorized();
    }
}
