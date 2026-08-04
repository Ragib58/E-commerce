<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\TokenAbility;
use App\Models\Product;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Saved products, and the bulk lookup that backs the compare tray and the
 * recently-viewed rail.
 */
final class WishlistTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();

        $this->customer = User::factory()->create();
    }

    private function asCustomer(?User $user = null): self
    {
        $token = ($user ?? $this->customer)
            ->createToken('t', [TokenAbility::CustomerAccess->value])
            ->plainTextToken;

        return $this->withToken($token);
    }

    /*
    |--------------------------------------------------------------------------
    | Wishlist
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_customer_can_save_a_product(): void
    {
        $product = Product::factory()->published()->create();

        $this->asCustomer()
            ->postJson('/api/v1/wishlist', ['product' => $product->slug])
            ->assertCreated()
            ->assertJsonPath('data.saved.0', $product->uuid);

        $this->assertDatabaseHas('wishlist_items', [
            'user_id' => $this->customer->id,
            'product_id' => $product->id,
        ]);
    }

    #[Test]
    public function saving_the_same_product_twice_is_idempotent(): void
    {
        $product = Product::factory()->published()->create();

        foreach (range(1, 3) as $ignored) {
            $this->asCustomer()
                ->postJson('/api/v1/wishlist', ['product' => $product->slug])
                ->assertCreated();
        }

        // A double-click is not an error the shopper has to understand.
        $this->assertSame(1, WishlistItem::query()->count());
    }

    #[Test]
    public function the_wishlist_returns_products_not_wrapper_rows(): void
    {
        $product = Product::factory()->published()->create(['name' => 'Saved Kettle']);

        WishlistItem::factory()->create([
            'user_id' => $this->customer->id,
            'product_id' => $product->id,
        ]);

        // The client renders the same ProductCard it uses everywhere else, so
        // a bespoke wrapper shape would need a translation step.
        $this->asCustomer()
            ->getJson('/api/v1/wishlist')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Saved Kettle')
            ->assertJsonPath('data.0.id', $product->uuid);
    }

    #[Test]
    public function an_unpublished_product_is_omitted_from_the_wishlist(): void
    {
        $visible = Product::factory()->published()->create(['name' => 'Still For Sale']);
        $withdrawn = Product::factory()->published()->create(['name' => 'Withdrawn']);

        foreach ([$visible, $withdrawn] as $product) {
            WishlistItem::factory()->create([
                'user_id' => $this->customer->id,
                'product_id' => $product->id,
            ]);
        }

        $withdrawn->forceFill(['status' => 'draft'])->save();

        $names = array_column(
            $this->asCustomer()->getJson('/api/v1/wishlist')->assertOk()->json('data'),
            'name',
        );

        // Unlike a cart line, a saved item that is no longer for sale is simply
        // not shown — and reappears if it is republished.
        $this->assertSame(['Still For Sale'], $names);
    }

    #[Test]
    public function a_customer_can_remove_a_saved_product(): void
    {
        $product = Product::factory()->published()->create();

        WishlistItem::factory()->create([
            'user_id' => $this->customer->id,
            'product_id' => $product->id,
        ]);

        $this->asCustomer()
            ->deleteJson("/api/v1/wishlist/{$product->uuid}")
            ->assertOk()
            ->assertJsonPath('data.saved', []);

        $this->assertDatabaseMissing('wishlist_items', [
            'user_id' => $this->customer->id,
            'product_id' => $product->id,
        ]);
    }

    #[Test]
    public function one_customer_cannot_see_or_remove_anothers_saved_items(): void
    {
        $other = User::factory()->create();
        $product = Product::factory()->published()->create();

        WishlistItem::factory()->create([
            'user_id' => $other->id,
            'product_id' => $product->id,
        ]);

        $this->asCustomer()->getJson('/api/v1/wishlist')->assertOk()->assertJsonCount(0, 'data');

        // A delete scoped to the caller leaves the other customer's row intact.
        $this->asCustomer()->deleteJson("/api/v1/wishlist/{$product->uuid}")->assertOk();

        $this->assertDatabaseHas('wishlist_items', [
            'user_id' => $other->id,
            'product_id' => $product->id,
        ]);
    }

    #[Test]
    public function a_guest_wishlist_is_merged_on_sign_in(): void
    {
        $first = Product::factory()->published()->create();
        $second = Product::factory()->published()->create();

        $this->asCustomer()
            ->postJson('/api/v1/wishlist/merge', [
                'products' => [$first->uuid, $second->slug],
            ])
            ->assertOk()
            ->assertJsonPath('meta.merged', 2);

        $this->assertSame(2, WishlistItem::query()->where('user_id', $this->customer->id)->count());
    }

    #[Test]
    public function merging_skips_unknown_identifiers_rather_than_failing(): void
    {
        $product = Product::factory()->published()->create();

        // One stale localStorage entry must not cost the shopper the rest of
        // their saved items.
        $this->asCustomer()
            ->postJson('/api/v1/wishlist/merge', [
                'products' => [$product->uuid, 'a-product-that-was-deleted'],
            ])
            ->assertOk()
            ->assertJsonPath('meta.merged', 1);
    }

    #[Test]
    public function merging_does_not_duplicate_already_saved_products(): void
    {
        $product = Product::factory()->published()->create();

        WishlistItem::factory()->create([
            'user_id' => $this->customer->id,
            'product_id' => $product->id,
        ]);

        $this->asCustomer()
            ->postJson('/api/v1/wishlist/merge', ['products' => [$product->uuid]])
            ->assertOk()
            ->assertJsonPath('meta.merged', 0);

        $this->assertSame(1, WishlistItem::query()->count());
    }

    #[Test]
    public function the_wishlist_requires_authentication(): void
    {
        // Unlike the cart, there is no guest wishlist server-side — see
        // WishlistService for why.
        $this->getJson('/api/v1/wishlist')->assertUnauthorized();
        $this->postJson('/api/v1/wishlist', ['product' => 'anything'])->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | Bulk lookup — compare tray and recently viewed
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function lookup_returns_products_in_the_requested_order(): void
    {
        $alpha = Product::factory()->published()->create(['name' => 'Alpha']);
        $beta = Product::factory()->published()->create(['name' => 'Beta']);
        $gamma = Product::factory()->published()->create(['name' => 'Gamma']);

        // Deliberately not ascending by id: a whereIn returns index order,
        // which would scramble a recently-viewed rail whose entire meaning is
        // its ordering.
        $response = $this->postJson('/api/v1/catalog/products/lookup', [
            'products' => [$gamma->uuid, $alpha->uuid, $beta->uuid],
        ])->assertOk();

        $this->assertSame(['Gamma', 'Alpha', 'Beta'], array_column($response->json('data'), 'name'));
    }

    #[Test]
    public function lookup_omits_products_that_are_no_longer_published(): void
    {
        $visible = Product::factory()->published()->create(['name' => 'Visible']);
        $draft = Product::factory()->draft()->create(['name' => 'Draft']);

        $response = $this->postJson('/api/v1/catalog/products/lookup', [
            'products' => [$visible->uuid, $draft->uuid],
        ])->assertOk();

        // An id that no longer resolves drops out rather than leaving a hole
        // the client must handle.
        $this->assertSame(['Visible'], array_column($response->json('data'), 'name'));
    }

    #[Test]
    public function lookup_is_open_to_guests(): void
    {
        $product = Product::factory()->published()->create();

        // The compare tray and recently-viewed rail are client-side and
        // available to anyone.
        $this->postJson('/api/v1/catalog/products/lookup', ['products' => [$product->slug]])
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function lookup_rejects_an_oversized_list(): void
    {
        $this->postJson('/api/v1/catalog/products/lookup', [
            'products' => array_fill(0, 40, 'some-slug'),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('products');
    }
}
