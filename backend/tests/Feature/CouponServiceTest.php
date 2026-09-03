<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coupon validation and discount computation.
 *
 * The brief's instruction is "validate coupon entirely on backend" — so these
 * tests never trust anything but CouponService's own answer. Each rule the
 * brief lists (percentage, fixed, minimum order, maximum discount,
 * product-specific, category-specific, user-specific, first-order, expiry,
 * usage limit, per-user limit) gets its own assertion, because a coupon system
 * where one of eleven rules silently does not apply is a coupon system that
 * gives away money nobody authorised.
 */
final class CouponServiceTest extends TestCase
{
    use RefreshDatabase;

    private function coupons(): CouponService
    {
        return $this->app->make(CouponService::class);
    }

    /**
     * A priced cart summary the way CartService actually produces one — real
     * catalog rows, not a hand-built array, so a test asserting on
     * CouponService's reading of `cartSummary` is exercising the real shape.
     */
    private function summaryFor(Cart $cart): array
    {
        return $this->app->make(CartService::class)->summariseWithoutCoupon($cart);
    }

    private function cartWithProduct(Product $product, int $quantity = 1): Cart
    {
        $cart = Cart::factory()->create();

        $cart->items()->create([
            'product_id' => $product->getKey(),
            'product_variant_id' => null,
            'quantity' => $quantity,
        ]);

        return $cart;
    }

    /*
    |--------------------------------------------------------------------------
    | Percentage and fixed discounts
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_percentage_coupon_discounts_the_eligible_subtotal(): void
    {
        $product = Product::factory()->published()->create(['price' => 10_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);
        $coupon = Coupon::factory()->percentage(20)->create();

        $result = $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), null, 'guest@example.test');

        $this->assertSame(2_000, $result['discount']);
    }

    #[Test]
    public function a_fixed_coupon_deducts_the_exact_amount(): void
    {
        $product = Product::factory()->published()->create(['price' => 10_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);
        $coupon = Coupon::factory()->fixed(1_500)->create();

        $result = $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), null, 'guest@example.test');

        $this->assertSame(1_500, $result['discount']);
    }

    #[Test]
    public function a_fixed_coupon_cannot_discount_more_than_the_order(): void
    {
        // A discount larger than the eligible amount would be money the store
        // owes the customer for shopping.
        $product = Product::factory()->published()->create(['price' => 500, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);
        $coupon = Coupon::factory()->fixed(50_000)->create();

        $result = $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), null, 'guest@example.test');

        $this->assertSame(500, $result['discount']);
    }

    #[Test]
    public function a_percentage_coupon_respects_its_maximum_discount(): void
    {
        $product = Product::factory()->published()->create(['price' => 100_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);
        $coupon = Coupon::factory()->percentage(50, maxDiscount: 10_000)->create();

        $result = $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), null, 'guest@example.test');

        // 50% of 100,000 would be 50,000; the cap brings it to 10,000.
        $this->assertSame(10_000, $result['discount']);
    }

    /*
    |--------------------------------------------------------------------------
    | Minimum order amount
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_coupon_below_its_minimum_order_amount_is_refused(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);
        $coupon = Coupon::factory()->percentage(10)->minOrderAmount(5_000)->create();

        $this->expectException(ValidationException::class);

        $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), null, 'guest@example.test');
    }

    #[Test]
    public function a_coupon_at_exactly_its_minimum_order_amount_is_accepted(): void
    {
        $product = Product::factory()->published()->create(['price' => 5_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);
        $coupon = Coupon::factory()->percentage(10)->minOrderAmount(5_000)->create();

        $result = $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), null, 'guest@example.test');

        $this->assertSame(500, $result['discount']);
    }

    /*
    |--------------------------------------------------------------------------
    | Product- and category-specific scope
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_product_specific_coupon_only_discounts_the_named_product(): void
    {
        $targeted = Product::factory()->published()->create(['price' => 10_000, 'stock' => 10]);
        $other = Product::factory()->published()->create(['price' => 5_000, 'stock' => 10]);

        $cart = Cart::factory()->create();
        $cart->items()->create(['product_id' => $targeted->getKey(), 'product_variant_id' => null, 'quantity' => 1]);
        $cart->items()->create(['product_id' => $other->getKey(), 'product_variant_id' => null, 'quantity' => 1]);

        $coupon = Coupon::factory()->percentage(10)->notApplicableToAll()->create();
        $coupon->products()->attach($targeted->getKey(), ['is_excluded' => false]);

        $result = $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), null, 'guest@example.test');

        // 10% of only the targeted product's 10,000 — not the 15,000 total.
        $this->assertSame(1_000, $result['discount']);
    }

    #[Test]
    public function a_category_specific_coupon_discounts_matching_lines_only(): void
    {
        $category = Category::factory()->create();
        $inCategory = Product::factory()->published()->create([
            'price' => 8_000, 'stock' => 10, 'category_id' => $category->getKey(),
        ]);
        $outsideCategory = Product::factory()->published()->create(['price' => 4_000, 'stock' => 10]);

        $cart = Cart::factory()->create();
        $cart->items()->create(['product_id' => $inCategory->getKey(), 'product_variant_id' => null, 'quantity' => 1]);
        $cart->items()->create(['product_id' => $outsideCategory->getKey(), 'product_variant_id' => null, 'quantity' => 1]);

        $coupon = Coupon::factory()->percentage(25)->notApplicableToAll()->create();
        $coupon->categories()->attach($category->getKey(), ['is_excluded' => false, 'includes_descendants' => true]);

        $result = $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), null, 'guest@example.test');

        $this->assertSame(2_000, $result['discount']);
    }

    #[Test]
    public function an_excluded_product_is_never_discounted_even_if_its_category_matches(): void
    {
        // An exclusion must win over a category match, otherwise "everything in
        // Electronics except gift cards" cannot be expressed.
        $category = Category::factory()->create();
        $excluded = Product::factory()->published()->create([
            'price' => 6_000, 'stock' => 10, 'category_id' => $category->getKey(),
        ]);

        $cart = $this->cartWithProduct($excluded);

        $coupon = Coupon::factory()->percentage(20)->notApplicableToAll()->create();
        $coupon->categories()->attach($category->getKey(), ['is_excluded' => false, 'includes_descendants' => true]);
        $coupon->products()->attach($excluded->getKey(), ['is_excluded' => true]);

        $this->expectException(ValidationException::class);

        // Nothing is eligible, so the coupon does not apply at all.
        $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), null, 'guest@example.test');
    }

    #[Test]
    public function a_scoped_coupon_with_no_matching_line_is_refused(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $unrelated = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);

        $coupon = Coupon::factory()->percentage(10)->notApplicableToAll()->create();
        $coupon->products()->attach($unrelated->getKey(), ['is_excluded' => false]);

        $this->expectException(ValidationException::class);

        $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), null, 'guest@example.test');
    }

    /*
    |--------------------------------------------------------------------------
    | User-specific and first-order
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_user_restricted_coupon_is_refused_for_a_customer_not_on_the_list(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);
        $allowed = User::factory()->create();
        $stranger = User::factory()->create();

        $coupon = Coupon::factory()->percentage(10)->userRestricted()->create();
        $coupon->users()->attach($allowed->getKey());

        $this->expectException(ValidationException::class);

        $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), $stranger, $stranger->email);
    }

    #[Test]
    public function a_user_restricted_coupon_works_for_a_listed_customer(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);
        $allowed = User::factory()->create();

        $coupon = Coupon::factory()->percentage(10)->userRestricted()->create();
        $coupon->users()->attach($allowed->getKey());

        $result = $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), $allowed, $allowed->email);

        $this->assertSame(100, $result['discount']);
    }

    #[Test]
    public function a_user_restricted_coupon_is_refused_for_a_guest(): void
    {
        // A guest has no account to be named on the allow list.
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);

        $coupon = Coupon::factory()->percentage(10)->userRestricted()->create();

        $this->expectException(ValidationException::class);

        $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), null, 'guest@example.test');
    }

    #[Test]
    public function a_first_order_coupon_works_for_a_customer_with_no_prior_orders(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);
        $user = User::factory()->create();

        $coupon = Coupon::factory()->percentage(10)->firstOrderOnly()->create();

        $result = $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), $user, $user->email);

        $this->assertSame(100, $result['discount']);
    }

    #[Test]
    public function a_first_order_coupon_is_refused_for_a_returning_customer(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);
        $user = User::factory()->create();

        Order::factory()->forUser($user)->delivered()->create();

        $coupon = Coupon::factory()->percentage(10)->firstOrderOnly()->create();

        $this->expectException(ValidationException::class);

        $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), $user, $user->email);
    }

    #[Test]
    public function a_cancelled_prior_order_does_not_disqualify_a_first_order_coupon(): void
    {
        // A customer whose first attempt was cancelled has not yet had the
        // order the welcome discount was meant to apply to.
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);
        $user = User::factory()->create();

        Order::factory()->forUser($user)->cancelled()->create();

        $coupon = Coupon::factory()->percentage(10)->firstOrderOnly()->create();

        $result = $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), $user, $user->email);

        $this->assertSame(100, $result['discount']);
    }

    #[Test]
    public function a_first_order_coupon_is_refused_for_a_guest_who_ordered_before_with_the_same_email(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);

        Order::factory()->delivered()->create(['customer_email' => 'repeat@example.test']);

        $coupon = Coupon::factory()->percentage(10)->firstOrderOnly()->create();

        $this->expectException(ValidationException::class);

        $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), null, 'repeat@example.test');
    }

    /*
    |--------------------------------------------------------------------------
    | Expiry and activity window
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_expired_coupon_is_refused(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);
        $coupon = Coupon::factory()->percentage(10)->expired()->create();

        $this->expectException(ValidationException::class);

        $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), null, 'guest@example.test');
    }

    #[Test]
    public function a_coupon_not_yet_started_is_refused(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);
        $coupon = Coupon::factory()->percentage(10)->notYetStarted()->create();

        $this->expectException(ValidationException::class);

        $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), null, 'guest@example.test');
    }

    #[Test]
    public function a_deactivated_coupon_is_refused(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);
        $coupon = Coupon::factory()->percentage(10)->inactive()->create();

        $this->expectException(ValidationException::class);

        $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), null, 'guest@example.test');
    }

    /*
    |--------------------------------------------------------------------------
    | Usage limits
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_coupon_that_reached_its_global_usage_limit_is_refused(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);
        $coupon = Coupon::factory()->percentage(10)->usageLimit(5)->used(5)->create();

        $this->expectException(ValidationException::class);

        $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), null, 'guest@example.test');
    }

    #[Test]
    public function a_coupon_one_use_below_its_limit_still_works(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);
        $coupon = Coupon::factory()->percentage(10)->usageLimit(5)->used(4)->create();

        $result = $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), null, 'guest@example.test');

        $this->assertSame(100, $result['discount']);
    }

    #[Test]
    public function a_customer_who_reached_their_per_user_limit_is_refused(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);
        $user = User::factory()->create();

        $coupon = Coupon::factory()->percentage(10)->perUserLimit(2)->create();

        CouponUsage::factory()->forCoupon($coupon)->forUser($user)->count(2)->create();

        $this->expectException(ValidationException::class);

        $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), $user, $user->email);
    }

    #[Test]
    public function the_per_user_limit_is_tracked_by_email_for_a_guest(): void
    {
        /*
         * A guest has no account row to attach a per-user counter to, so the
         * limit must be enforced by email — the same dual key guest order
         * lookup already uses.
         */
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);

        $coupon = Coupon::factory()->percentage(10)->perUserLimit(1)->create();

        CouponUsage::factory()->forCoupon($coupon)->create(['customer_email' => 'guest@example.test']);

        $this->expectException(ValidationException::class);

        $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), null, 'guest@example.test');
    }

    #[Test]
    public function a_per_user_limit_does_not_affect_a_different_customer(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);

        $coupon = Coupon::factory()->percentage(10)->perUserLimit(1)->create();

        CouponUsage::factory()->forCoupon($coupon)->create(['customer_email' => 'someone-else@example.test']);

        $result = $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), null, 'guest@example.test');

        $this->assertSame(100, $result['discount']);
    }

    /*
    |--------------------------------------------------------------------------
    | Free shipping
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_free_shipping_coupon_reports_the_flag(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);
        $coupon = Coupon::factory()->percentage(0.01)->freeShipping()->create();

        $result = $this->coupons()->preview($coupon->code, $cart, $this->summaryFor($cart), null, 'guest@example.test');

        $this->assertTrue($result['shipping_waived']);
    }

    /*
    |--------------------------------------------------------------------------
    | An unknown code
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_unknown_code_is_refused_with_a_generic_message(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);

        $this->expectException(ValidationException::class);

        $this->coupons()->preview('NOT-A-REAL-CODE', $cart, $this->summaryFor($cart), null, 'guest@example.test');
    }

    #[Test]
    public function a_code_is_matched_case_insensitively(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);
        Coupon::factory()->percentage(10)->create(['code' => 'SAVE10']);

        $result = $this->coupons()->preview('save10', $cart, $this->summaryFor($cart), null, 'guest@example.test');

        $this->assertSame(100, $result['discount']);
    }

    /*
    |--------------------------------------------------------------------------
    | Redemption — the two-phase design OrderService relies on
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function redeeming_increments_the_usage_counter(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);
        $coupon = Coupon::factory()->percentage(10)->create();

        $result = $this->coupons()->redeemPending(
            $coupon->code,
            $this->summaryFor($cart),
            null,
            'guest@example.test',
        );

        $this->assertSame(100, $result['discount']);
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    #[Test]
    public function recording_a_redemption_writes_the_usage_ledger(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);
        $coupon = Coupon::factory()->percentage(10)->create();
        $order = Order::factory()->create();

        $this->coupons()->redeemPending($coupon->code, $this->summaryFor($cart), null, 'guest@example.test');
        $this->coupons()->recordRedemption($coupon->code, $order, 100, null, 'guest@example.test');

        $this->assertDatabaseHas('coupon_usages', [
            'coupon_id' => $coupon->getKey(),
            'order_id' => $order->getKey(),
            'discount_amount' => 100,
            'customer_email' => 'guest@example.test',
        ]);
    }

    #[Test]
    public function redeeming_a_coupon_at_its_last_use_still_succeeds(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);
        $coupon = Coupon::factory()->percentage(10)->usageLimit(3)->used(2)->create();

        $this->coupons()->redeemPending($coupon->code, $this->summaryFor($cart), null, 'guest@example.test');

        $this->assertSame(3, $coupon->fresh()->used_count);
    }

    #[Test]
    public function redeeming_beyond_the_usage_limit_throws_and_does_not_increment(): void
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 10]);
        $cart = $this->cartWithProduct($product);
        $coupon = Coupon::factory()->percentage(10)->usageLimit(3)->used(3)->create();

        try {
            $this->coupons()->redeemPending($coupon->code, $this->summaryFor($cart), null, 'guest@example.test');
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            // Expected.
        }

        $this->assertSame(3, $coupon->fresh()->used_count, 'A refused redemption must not increment the counter.');
    }

    /*
    |--------------------------------------------------------------------------
    | Public coupon discovery
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function only_public_coupons_are_listed(): void
    {
        Coupon::factory()->public()->create(['code' => 'PUBLIC1']);
        Coupon::factory()->create(['code' => 'PRIVATE1']); // is_public defaults false.

        $codes = collect($this->coupons()->publicCoupons())->pluck('code');

        $this->assertContains('PUBLIC1', $codes);
        $this->assertNotContains('PRIVATE1', $codes);
    }

    #[Test]
    public function an_expired_public_coupon_is_not_listed(): void
    {
        Coupon::factory()->public()->expired()->create(['code' => 'EXPIREDPUB']);

        $codes = collect($this->coupons()->publicCoupons())->pluck('code');

        $this->assertNotContains('EXPIREDPUB', $codes);
    }

    #[Test]
    public function a_public_coupon_that_reached_its_limit_is_not_listed(): void
    {
        Coupon::factory()->public()->usageLimit(1)->used(1)->create(['code' => 'EXHAUSTEDPUB']);

        $codes = collect($this->coupons()->publicCoupons())->pluck('code');

        $this->assertNotContains('EXHAUSTEDPUB', $codes);
    }
}
