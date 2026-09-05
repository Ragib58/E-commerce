<?php

declare(strict_types=1);

namespace Tests\Feature\ProductionReadiness;

use App\Enums\TokenAbility;
use App\Models\Admin;
use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression cover for a cross-principal cart leak.
 *
 * ## The bug
 *
 * The cart routes deliberately carry no `auth:sanctum` guard, because guests
 * must be able to shop — the `cart` middleware resolves by user id when a token
 * is present and by `X-Cart-Token` when it is not. That part is correct.
 *
 * What was missing is that `$request->user()` on an unguarded route resolves
 * *any* valid Sanctum token, and this application issues tokens to two
 * different tables. An `Admin` row with id 7 therefore resolved to
 * `carts.user_id = 7` — and `carts.user_id` is a foreign key to `users`. So a
 * staff token read and mutated the cart belonging to **customer** number 7.
 *
 * Not a privilege escalation upward, but a cross-tenant data leak in both
 * directions: staff see a shopper's basket, and anything they add appears in
 * that shopper's cart and can be carried into their checkout.
 *
 * The fix scopes cart resolution to `User` principals only. An admin token now
 * falls through to the guest path, which is the honest answer: staff have no
 * shopping cart.
 */
final class AdminTokenCartLeakTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();
    }

    /**
     * Force an Admin and a User to share a primary key.
     *
     * They are separate tables with independent sequences, so in any real
     * deployment the low ids collide constantly — id 1 is the first admin
     * seeded *and* the first customer to register. Making it explicit here
     * keeps the test deterministic rather than dependent on insert order.
     */
    private function collidingPrincipals(): array
    {
        $user = User::factory()->create(['email' => 'shopper@example.test']);

        $admin = Admin::factory()->create(['email' => 'staff@example.test']);
        DB::table('admins')->where('id', $admin->id)->update(['id' => $user->id]);
        $admin = Admin::query()->findOrFail($user->id);

        $this->assertSame($user->id, $admin->id, 'The test needs a colliding id to be meaningful.');

        return [$user, $admin];
    }

    #[Test]
    public function an_admin_token_cannot_read_the_cart_of_the_customer_sharing_its_id(): void
    {
        [$user, $admin] = $this->collidingPrincipals();

        $product = Product::factory()->published()->create(['price' => 9_900, 'stock' => 5]);

        // The shopper fills their basket.
        $userToken = $user->createToken('t', [TokenAbility::CustomerAccess->value])->plainTextToken;

        $this->withToken($userToken)
            ->postJson('/api/v1/cart/items', ['product' => $product->slug, 'quantity' => 2])
            ->assertCreated()
            ->assertJsonPath('data.totals.subtotal', 19_800);

        // Staff hit the same endpoint with an admin token.
        $adminToken = $admin->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken;

        $response = $this->withToken($adminToken)->getJson('/api/v1/cart')->assertOk();

        $this->assertSame(
            0,
            $response->json('data.item_count'),
            'An admin token must not resolve to the cart of the customer sharing its id.',
        );

        // And the shopper's cart is untouched and still theirs.
        $this->withToken($userToken)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.totals.subtotal', 19_800);
    }

    #[Test]
    public function an_admin_token_cannot_write_into_a_customers_cart(): void
    {
        [$user, $admin] = $this->collidingPrincipals();

        $product = Product::factory()->published()->create(['price' => 5_000, 'stock' => 10]);

        $adminToken = $admin->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken;

        $this->withToken($adminToken)
            ->postJson('/api/v1/cart/items', ['product' => $product->slug, 'quantity' => 3]);

        // Whatever that request did, it must not have landed in the customer's
        // cart — which is what the shopper would otherwise carry to checkout.
        $userCart = Cart::query()->where('user_id', $user->id)->first();

        $this->assertTrue(
            $userCart === null || $userCart->items()->count() === 0,
            'An admin token wrote a line into a customer\'s cart.',
        );
    }

    #[Test]
    public function a_cart_is_never_created_against_an_admin_id(): void
    {
        [$user, $admin] = $this->collidingPrincipals();

        $product = Product::factory()->published()->create(['stock' => 5]);
        $adminToken = $admin->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken;

        $this->withToken($adminToken)
            ->postJson('/api/v1/cart/items', ['product' => $product->slug]);

        /*
         * The strongest form of the assertion: no row in `carts` claims this id
         * as its owner. `carts.user_id` is a foreign key into `users`, so a row
         * written from an admin's id is silently mislabelled as belonging to a
         * customer who never created it.
         */
        $this->assertDatabaseMissing('carts', ['user_id' => $admin->id]);
    }
}
