<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\TokenAbility;
use App\Http\Middleware\ResolveCart;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The shopping cart.
 *
 * The assertions that matter most are the pricing ones. A cart that can be told
 * what something costs is not a cart, it is a donation form — so the tests
 * below try to submit prices through every field the API exposes and assert
 * that the catalog's figures survive unchanged.
 */
final class CartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();
    }

    /**
     * A guest cart credential, as the middleware's shape check requires.
     */
    private function guestToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function asCustomer(User $user): self
    {
        $token = $user->createToken('t', [TokenAbility::CustomerAccess->value])->plainTextToken;

        return $this->withToken($token);
    }

    /**
     * Set the store's tax rate.
     *
     * The type is passed explicitly: with no seeded row the service defaults to
     * String, and the value would then cast back as a string rather than the
     * float the tax calculation multiplies by. The cache flush matters too —
     * SettingsService memoises, and the rate is read during the same request.
     */
    private function setTaxRate(float $rate): void
    {
        app(\App\Services\SettingsService::class)->set(
            'business.tax_rate',
            $rate,
            \App\Enums\SettingType::Float,
            \App\Enums\SettingGroup::Business,
        );

        $this->app->make('cache')->flush();
    }

    /*
    |--------------------------------------------------------------------------
    | Server-side pricing — the core guarantee
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_cart_prices_a_line_from_the_catalog(): void
    {
        $product = Product::factory()->published()->create(['price' => 2_500, 'stock' => 10]);

        $response = $this->withHeader(ResolveCart::HEADER, $this->guestToken())
            ->postJson('/api/v1/cart/items', ['product' => $product->slug, 'quantity' => 2])
            ->assertCreated();

        $response
            ->assertJsonPath('data.items.0.unit_price', 2_500)
            ->assertJsonPath('data.items.0.line_total', 5_000)
            ->assertJsonPath('data.totals.subtotal', 5_000);
    }

    #[Test]
    public function a_submitted_price_is_ignored_entirely(): void
    {
        $product = Product::factory()->published()->create(['price' => 2_500, 'stock' => 10]);

        // Every field a naive implementation might read a price from.
        $this->withHeader(ResolveCart::HEADER, $this->guestToken())
            ->postJson('/api/v1/cart/items', [
                'product' => $product->slug,
                'quantity' => 1,
                'price' => 1,
                'unit_price' => 1,
                'discount_price' => 1,
                'line_total' => 1,
                'subtotal' => 1,
                'total' => 1,
            ])
            ->assertCreated()
            // The catalog's price, not the request's.
            ->assertJsonPath('data.items.0.unit_price', 2_500)
            ->assertJsonPath('data.totals.subtotal', 2_500)
            ->assertJsonPath('data.totals.total', 2_500);

        // And nothing resembling a price was persisted from the request.
        $this->assertDatabaseMissing('cart_items', ['quantity' => 1, 'product_id' => $product->id, 'cart_id' => null]);
    }

    #[Test]
    public function a_discounted_product_is_priced_at_its_discount(): void
    {
        $product = Product::factory()->published()->create([
            'price' => 4_000,
            'discount_price' => 3_000,
            'stock' => 10,
        ]);

        $this->withHeader(ResolveCart::HEADER, $this->guestToken())
            ->postJson('/api/v1/cart/items', ['product' => $product->slug, 'quantity' => 2])
            ->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', 3_000)
            ->assertJsonPath('data.items.0.list_price', 4_000)
            ->assertJsonPath('data.items.0.line_total', 6_000)
            // 1,000 off each of two units.
            ->assertJsonPath('data.totals.discount', 2_000);
    }

    #[Test]
    public function a_price_change_is_reflected_on_the_next_read(): void
    {
        $product = Product::factory()->published()->create(['price' => 2_000, 'stock' => 10]);
        $token = $this->guestToken();

        $this->withHeader(ResolveCart::HEADER, $token)
            ->postJson('/api/v1/cart/items', ['product' => $product->slug])
            ->assertCreated()
            ->assertJsonPath('data.totals.subtotal', 2_000);

        $product->forceFill(['price' => 2_600])->save();

        // Nothing was stored to become stale: the figure is derived on read.
        $this->withHeader(ResolveCart::HEADER, $token)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.items.0.unit_price', 2_600)
            ->assertJsonPath('data.totals.subtotal', 2_600);
    }

    #[Test]
    public function a_variant_price_overrides_the_parent_product(): void
    {
        $product = Product::factory()->variable()->published()->create(['price' => 2_000]);

        $variant = ProductVariant::factory()->for($product)->create([
            'price' => 3_500,
            'stock' => 5,
            'is_active' => true,
        ]);

        $this->withHeader(ResolveCart::HEADER, $this->guestToken())
            ->postJson('/api/v1/cart/items', [
                'product' => $product->slug,
                'variant' => $variant->uuid,
                'quantity' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', 3_500)
            ->assertJsonPath('data.totals.subtotal', 7_000);
    }

    #[Test]
    public function a_variant_without_its_own_price_inherits_the_products(): void
    {
        $product = Product::factory()->variable()->published()->create(['price' => 2_000]);

        $variant = ProductVariant::factory()->for($product)->create([
            'price' => null,
            'stock' => 5,
            'is_active' => true,
        ]);

        // Inheritance must resolve to the parent's price, not to zero — a
        // silent zero here would give the catalog away.
        $this->withHeader(ResolveCart::HEADER, $this->guestToken())
            ->postJson('/api/v1/cart/items', [
                'product' => $product->slug,
                'variant' => $variant->uuid,
            ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', 2_000);
    }

    #[Test]
    public function a_variant_belonging_to_another_product_is_refused(): void
    {
        $cheap = Product::factory()->published()->create(['price' => 100, 'stock' => 5]);
        $expensive = Product::factory()->variable()->published()->create(['price' => 90_000]);

        $variant = ProductVariant::factory()->for($expensive)->create(['price' => 90_000, 'stock' => 5]);

        // Pairing a cheap product with an expensive product's variant must not
        // resolve; the variant lookup is scoped to the named product.
        $this->withHeader(ResolveCart::HEADER, $this->guestToken())
            ->postJson('/api/v1/cart/items', [
                'product' => $cheap->slug,
                'variant' => $variant->uuid,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant');
    }

    #[Test]
    public function tax_is_computed_from_the_store_setting(): void
    {
        $this->setTaxRate(10.0);

        $product = Product::factory()->published()->create([
            'price' => 10_000,
            'stock' => 5,
            'is_taxable' => true,
        ]);

        $this->withHeader(ResolveCart::HEADER, $this->guestToken())
            ->postJson('/api/v1/cart/items', ['product' => $product->slug])
            ->assertCreated()
            ->assertJsonPath('data.totals.subtotal', 10_000)
            ->assertJsonPath('data.totals.tax', 1_000)
            ->assertJsonPath('data.totals.total', 11_000);
    }

    #[Test]
    public function a_non_taxable_product_is_excluded_from_tax(): void
    {
        $this->setTaxRate(20.0);

        $product = Product::factory()->published()->create([
            'price' => 5_000,
            'stock' => 5,
            'is_taxable' => false,
        ]);

        $this->withHeader(ResolveCart::HEADER, $this->guestToken())
            ->postJson('/api/v1/cart/items', ['product' => $product->slug])
            ->assertCreated()
            ->assertJsonPath('data.totals.tax', 0)
            ->assertJsonPath('data.totals.total', 5_000);
    }

    /*
    |--------------------------------------------------------------------------
    | Stock
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function adding_more_than_is_in_stock_is_refused(): void
    {
        $product = Product::factory()->published()->create(['stock' => 3, 'allow_backorder' => false]);

        $this->withHeader(ResolveCart::HEADER, $this->guestToken())
            ->postJson('/api/v1/cart/items', ['product' => $product->slug, 'quantity' => 4])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');
    }

    #[Test]
    public function a_second_add_is_checked_against_the_resulting_quantity(): void
    {
        $product = Product::factory()->published()->create(['stock' => 3, 'allow_backorder' => false]);
        $token = $this->guestToken();

        $this->withHeader(ResolveCart::HEADER, $token)
            ->postJson('/api/v1/cart/items', ['product' => $product->slug, 'quantity' => 3])
            ->assertCreated();

        // One more is available in isolation, but not on top of the three
        // already held. Checking the delta rather than the total would allow it.
        $this->withHeader(ResolveCart::HEADER, $token)
            ->postJson('/api/v1/cart/items', ['product' => $product->slug, 'quantity' => 1])
            ->assertStatus(422);
    }

    #[Test]
    public function a_line_that_outruns_stock_is_flagged_rather_than_dropped(): void
    {
        $product = Product::factory()->published()->create(['stock' => 5, 'allow_backorder' => false]);
        $token = $this->guestToken();

        $this->withHeader(ResolveCart::HEADER, $token)
            ->postJson('/api/v1/cart/items', ['product' => $product->slug, 'quantity' => 5])
            ->assertCreated();

        // Stock falls after the item is already in the cart — a cart does not
        // reserve, so this is the ordinary case rather than an edge one.
        $product->forceFill(['stock' => 2])->save();

        $response = $this->withHeader(ResolveCart::HEADER, $token)
            ->getJson('/api/v1/cart')
            ->assertOk();

        // Still present, so the shopper can see what happened and act on it.
        $response->assertJsonPath('data.items.0.issues.0.code', 'INSUFFICIENT_STOCK');
        $response->assertJsonPath('data.has_issues', true);
    }

    #[Test]
    public function an_unsellable_line_does_not_contribute_to_the_total(): void
    {
        $available = Product::factory()->published()->create(['price' => 1_000, 'stock' => 5]);
        $soldOut = Product::factory()->published()->create(['price' => 9_999, 'stock' => 5]);
        $token = $this->guestToken();

        foreach ([$available, $soldOut] as $product) {
            $this->withHeader(ResolveCart::HEADER, $token)
                ->postJson('/api/v1/cart/items', ['product' => $product->slug])
                ->assertCreated();
        }

        $soldOut->forceFill(['stock' => 0, 'allow_backorder' => false])->save();

        // Charging for something that cannot ship is worse than a short total.
        $this->withHeader(ResolveCart::HEADER, $token)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.totals.subtotal', 1_000);
    }

    #[Test]
    public function an_unpublished_product_cannot_be_added(): void
    {
        $draft = Product::factory()->draft()->create(['slug' => 'unreleased']);

        // Guessing the slug of an unreleased product must not price it.
        $this->withHeader(ResolveCart::HEADER, $this->guestToken())
            ->postJson('/api/v1/cart/items', ['product' => 'unreleased'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('product');
    }

    #[Test]
    public function a_variable_product_requires_a_variant(): void
    {
        $product = Product::factory()->variable()->published()->create();

        ProductVariant::factory()->for($product)->create(['stock' => 5]);

        // "A t-shirt" with no size is an unfulfillable line.
        $this->withHeader(ResolveCart::HEADER, $this->guestToken())
            ->postJson('/api/v1/cart/items', ['product' => $product->slug])
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant');
    }

    /*
    |--------------------------------------------------------------------------
    | Line management
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function adding_the_same_product_twice_increments_one_line(): void
    {
        $product = Product::factory()->published()->create(['stock' => 10]);
        $token = $this->guestToken();

        foreach ([1, 2] as $quantity) {
            $this->withHeader(ResolveCart::HEADER, $token)
                ->postJson('/api/v1/cart/items', ['product' => $product->slug, 'quantity' => $quantity])
                ->assertCreated();
        }

        $response = $this->withHeader(ResolveCart::HEADER, $token)
            ->getJson('/api/v1/cart')
            ->assertOk();

        // One line at quantity 3, not two lines splitting it.
        $response->assertJsonCount(1, 'data.items');
        $response->assertJsonPath('data.items.0.quantity', 3);
    }

    #[Test]
    public function a_quantity_of_zero_removes_the_line(): void
    {
        $product = Product::factory()->published()->create(['stock' => 10]);
        $token = $this->guestToken();

        $this->withHeader(ResolveCart::HEADER, $token)
            ->postJson('/api/v1/cart/items', ['product' => $product->slug])
            ->assertCreated();

        $itemId = Cart::query()->forToken($token)->firstOrFail()->items()->firstOrFail()->id;

        $this->withHeader(ResolveCart::HEADER, $token)
            ->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 0])
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    #[Test]
    public function a_line_in_another_shoppers_cart_cannot_be_touched(): void
    {
        $product = Product::factory()->published()->create(['stock' => 10]);

        $victimToken = $this->guestToken();
        $this->withHeader(ResolveCart::HEADER, $victimToken)
            ->postJson('/api/v1/cart/items', ['product' => $product->slug])
            ->assertCreated();

        $victimItemId = Cart::query()->forToken($victimToken)->firstOrFail()->items()->firstOrFail()->id;

        // A different shopper naming the victim's line id. Item lookups are
        // scoped to the requesting cart, so this resolves to nothing.
        $this->withHeader(ResolveCart::HEADER, $this->guestToken())
            ->patchJson("/api/v1/cart/items/{$victimItemId}", ['quantity' => 99])
            ->assertStatus(422);

        $this->assertDatabaseHas('cart_items', ['id' => $victimItemId, 'quantity' => 1]);
    }

    #[Test]
    public function the_cart_can_be_emptied(): void
    {
        $product = Product::factory()->published()->create(['stock' => 10]);
        $token = $this->guestToken();

        $this->withHeader(ResolveCart::HEADER, $token)
            ->postJson('/api/v1/cart/items', ['product' => $product->slug])
            ->assertCreated();

        $this->withHeader(ResolveCart::HEADER, $token)
            ->deleteJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.item_count', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Guest and authenticated resolution
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_empty_cart_is_returned_rather_than_a_404(): void
    {
        // First use is an empty cart, not a missing one — a 404 would make
        // every client special-case the very first page load.
        $this->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.item_count', 0)
            ->assertJsonPath('data.totals.total', 0);
    }

    #[Test]
    public function a_read_does_not_create_a_cart_row(): void
    {
        $this->getJson('/api/v1/cart')->assertOk();

        // Otherwise every crawler hit inserts a row nobody will ever use.
        $this->assertSame(0, Cart::query()->count());
    }

    #[Test]
    public function the_guest_token_is_returned_so_a_client_can_store_it(): void
    {
        $product = Product::factory()->published()->create(['stock' => 5]);

        $response = $this->postJson('/api/v1/cart/items', ['product' => $product->slug])
            ->assertCreated();

        $token = $response->headers->get(ResolveCart::HEADER);

        $this->assertNotNull($token);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    }

    #[Test]
    public function a_guest_token_cannot_reach_a_signed_in_customers_cart(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->published()->create(['stock' => 10]);

        $this->asCustomer($user)
            ->postJson('/api/v1/cart/items', ['product' => $product->slug])
            ->assertCreated();

        $cart = Cart::query()->forUser((int) $user->getKey())->firstOrFail();

        // A user's cart holds no token at all, so there is nothing for a
        // leaked or guessed header to match.
        $this->assertNull($cart->token);
    }

    #[Test]
    public function an_authenticated_request_ignores_a_supplied_guest_token(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);

        $guestToken = $this->guestToken();

        // A guest cart exists and holds an item.
        $this->withHeader(ResolveCart::HEADER, $guestToken)
            ->postJson('/api/v1/cart/items', ['product' => $product->slug, 'quantity' => 3])
            ->assertCreated();

        // The same header, now with a customer token attached. The user's own
        // (empty) cart must win — otherwise anyone holding a stranger's cart
        // cookie could act through their account.
        $this->asCustomer($user)
            ->withHeader(ResolveCart::HEADER, $guestToken)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.item_count', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | Merging on sign-in
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_guest_cart_is_claimed_on_merge(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->published()->create(['stock' => 10]);
        $guestToken = $this->guestToken();

        $this->withHeader(ResolveCart::HEADER, $guestToken)
            ->postJson('/api/v1/cart/items', ['product' => $product->slug, 'quantity' => 2])
            ->assertCreated();

        $this->asCustomer($user)
            ->withHeader(ResolveCart::HEADER, $guestToken)
            ->postJson('/api/v1/cart/merge')
            ->assertOk()
            ->assertJsonPath('data.item_count', 2);

        // The token is cleared once claimed, so the old cookie can no longer
        // reach a cart that now belongs to an account.
        $this->assertDatabaseHas('carts', ['user_id' => $user->id, 'token' => null]);
    }

    #[Test]
    public function merging_sums_quantities_for_the_same_product(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->published()->create(['stock' => 20]);

        // An existing customer cart.
        $this->asCustomer($user)
            ->postJson('/api/v1/cart/items', ['product' => $product->slug, 'quantity' => 2])
            ->assertCreated();

        // And a guest cart holding the same product.
        $guestToken = $this->guestToken();
        $this->withHeader(ResolveCart::HEADER, $guestToken)
            ->postJson('/api/v1/cart/items', ['product' => $product->slug, 'quantity' => 3])
            ->assertCreated();

        $response = $this->asCustomer($user)
            ->withHeader(ResolveCart::HEADER, $guestToken)
            ->postJson('/api/v1/cart/merge')
            ->assertOk();

        $response->assertJsonCount(1, 'data.items');
        $response->assertJsonPath('data.items.0.quantity', 5);

        // The guest row is gone rather than left orphaned.
        $this->assertDatabaseMissing('carts', ['token' => $guestToken]);
    }

    #[Test]
    public function merging_is_idempotent(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->published()->create(['stock' => 10]);
        $guestToken = $this->guestToken();

        $this->withHeader(ResolveCart::HEADER, $guestToken)
            ->postJson('/api/v1/cart/items', ['product' => $product->slug, 'quantity' => 2])
            ->assertCreated();

        foreach (range(1, 3) as $ignored) {
            $this->asCustomer($user)
                ->withHeader(ResolveCart::HEADER, $guestToken)
                ->postJson('/api/v1/cart/merge')
                ->assertOk()
                // A client calling this on every page load must not keep
                // doubling the quantity.
                ->assertJsonPath('data.item_count', 2);
        }

        $this->assertSame(1, Cart::query()->forUser((int) $user->getKey())->count());
    }

    #[Test]
    public function merge_requires_authentication(): void
    {
        $this->postJson('/api/v1/cart/merge')
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Coupon placeholder
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_coupon_code_is_stored_but_never_discounts(): void
    {
        $product = Product::factory()->published()->create(['price' => 5_000, 'stock' => 5]);
        $token = $this->guestToken();

        $this->withHeader(ResolveCart::HEADER, $token)
            ->postJson('/api/v1/cart/items', ['product' => $product->slug])
            ->assertCreated();

        $this->withHeader(ResolveCart::HEADER, $token)
            ->postJson('/api/v1/cart/coupon', ['coupon_code' => 'summer20'])
            ->assertOk()
            ->assertJsonPath('data.coupon.code', 'SUMMER20')
            // Explicitly not applied. A zero discount reported as "applied"
            // reads as a broken promotion rather than an unbuilt feature.
            ->assertJsonPath('data.coupon.applied', false)
            ->assertJsonPath('data.coupon.discount', 0)
            ->assertJsonPath('data.totals.total', 5_000);
    }
}
