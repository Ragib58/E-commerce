<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Coupon validation and discount computation.
 *
 * ## Validate entirely on the backend
 *
 * That is the brief's own framing, and it is stricter here than it sounds. It
 * is not merely "re-check what the client claims" — no request in this system
 * carries a discount figure for the server to check *against*. A client names
 * a code; every rule the coupon carries, and the discount it produces, is
 * derived here from the coupon row, the cart, and the customer. There is no
 * field anywhere a crafted request could put a discount into, the same
 * discipline CartService and OrderService already apply to prices.
 *
 * ## Two moments, two methods
 *
 * {@see preview()} is read-only: it answers "would this code work right now,
 * and for how much" without changing anything, and is what a client calls when
 * a shopper types a code into a box. {@see redeem()} is the one method that
 * actually consumes the entitlement — it increments the usage counter and
 * writes the ledger row — and it runs inside `OrderService`'s placing
 * transaction, never before an order is known to have been created.
 *
 * Splitting them is what stops a coupon being consumed by a shopper who typed
 * it, saw the discount, and then abandoned checkout. Applying it and charging
 * for it are different moments, and only the second should cost the coupon
 * anything.
 *
 * ## Locking
 *
 * `redeem()` locks the coupon row before checking the global usage limit and
 * before incrementing it — two concurrent checkouts racing for the last
 * remaining use of a limited coupon must serialise, or both can read
 * "one remaining" and both succeed, handing out one more redemption than the
 * limit allows. The same race the inventory ledger and order placement both
 * close with `lockForUpdate()`.
 */
final class CouponService
{
    /**
     * Whether a code would currently work for this cart and customer, and what
     * it would be worth. Changes nothing.
     *
     * @return array{coupon: Coupon, discount: int, shipping_waived: bool, eligible_amount: int}
     *
     * @throws ValidationException
     */
    public function preview(
        string $code,
        Cart $cart,
        array $cartSummary,
        ?User $user,
        string $customerEmail,
    ): array {
        $coupon = $this->findUsable($code);

        $this->assertRulesSatisfied($coupon, $cartSummary, $user, $customerEmail);

        $eligibleAmount = $this->eligibleAmount($coupon, $cartSummary);
        $discount = $coupon->calculateDiscount($eligibleAmount);

        return [
            'coupon' => $coupon,
            'discount' => $discount,
            'shipping_waived' => $coupon->free_shipping,
            'eligible_amount' => $eligibleAmount,
        ];
    }

    /**
     * Lock a coupon, re-validate it, and consume its usage entitlement — before
     * the order that will use it has an id.
     *
     * Split from {@see recordRedemption()} for a structural reason:
     * OrderService needs the discount amount to compute the order's totals
     * *before* it inserts the order row, but the usage ledger entry needs the
     * order's id, which does not exist yet at that point. Both calls must
     * happen inside the same outer transaction OrderService already holds —
     * the row lock taken here is what makes that safe, since it is held for
     * the transaction's duration and blocks a concurrent redemption of the
     * same coupon until this one commits or rolls back.
     *
     * Re-validates from scratch rather than trusting an earlier
     * {@see preview()}. Time passes between review and placement, and a coupon
     * that expired, hit its limit, or was deactivated in that window must not
     * be honoured just because it looked valid a moment ago.
     *
     * @return array{discount: int, shipping_waived: bool}
     *
     * @throws ValidationException
     */
    public function redeemPending(
        string $code,
        array $cartSummary,
        ?User $user,
        string $customerEmail,
    ): array {
        try {
            /*
             * Locked before any limit is checked, so a concurrent redemption of
             * the same coupon blocks here rather than racing past the
             * usage-limit check below.
             */
            $coupon = Coupon::query()
                ->lockForUpdate()
                ->byCode($code)
                ->first();

            if ($coupon === null) {
                throw ValidationException::withMessages([
                    'coupon_code' => ['That coupon code is not valid.'],
                ]);
            }

            $this->assertRulesSatisfied($coupon, $cartSummary, $user, $customerEmail);

            $eligibleAmount = $this->eligibleAmount($coupon, $cartSummary);
            $discount = $coupon->calculateDiscount($eligibleAmount);

            $coupon->increment('used_count');

            return [
                'discount' => $discount,
                'shipping_waived' => $coupon->free_shipping,
            ];
        } catch (QueryException $exception) {
            /*
             * A QueryException here would otherwise surface as an opaque 500 at
             * the worst possible moment, mid-checkout. Rethrown as a validation
             * failure so the shopper sees "try again" rather than a stack
             * trace.
             */
            throw ValidationException::withMessages([
                'coupon_code' => ['That coupon could not be applied. Please try again.'],
            ]);
        }
    }

    /**
     * Write the usage ledger row, once the order it belongs to exists.
     *
     * Must be called in the same transaction as {@see redeemPending()} — if
     * that transaction rolls back after this runs, both the usage counter
     * increment and this row roll back with it, which is exactly the
     * atomicity a limited coupon depends on. Calling this after the
     * transaction has already committed would let a failure between the two
     * calls consume a coupon's entitlement for an order that was never
     * actually created.
     */
    public function recordRedemption(
        string $code,
        Order $order,
        int $discount,
        ?User $user,
        string $customerEmail,
    ): CouponUsage {
        return CouponUsage::query()->create([
            'coupon_id' => Coupon::query()->byCode($code)->value('id'),
            'order_id' => $order->getKey(),
            'user_id' => $user?->getKey(),
            'customer_email' => $customerEmail,
            'coupon_code' => strtoupper(trim($code)),
            'discount_amount' => $discount,
        ]);
    }

    /**
     * @return array<int, array{value: string, label: string, description: ?string, type: string, free_shipping: bool}>
     */
    public function publicCoupons(): array
    {
        return Coupon::query()
            ->public()
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get()
            ->reject(fn (Coupon $coupon): bool => $coupon->hasReachedUsageLimit())
            ->map(fn (Coupon $coupon): array => [
                'code' => $coupon->code,
                'name' => $coupon->name,
                'description' => $coupon->description,
                'type' => $coupon->type->value,
                'free_shipping' => $coupon->free_shipping,
            ])
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Rule checks
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $cartSummary  CartService::summarise() output.
     *
     * @throws ValidationException
     */
    private function assertRulesSatisfied(
        Coupon $coupon,
        array $cartSummary,
        ?User $user,
        string $customerEmail,
    ): void {
        if (! $coupon->is_active) {
            throw ValidationException::withMessages([
                'coupon_code' => ['That coupon code is not valid.'],
            ]);
        }

        if (! $coupon->isWithinWindow()) {
            throw ValidationException::withMessages([
                'coupon_code' => [$coupon->expires_at !== null && $coupon->expires_at->isPast()
                    ? 'That coupon has expired.'
                    : 'That coupon is not active yet.'],
            ]);
        }

        if ($coupon->hasReachedUsageLimit()) {
            throw ValidationException::withMessages([
                'coupon_code' => ['That coupon has reached its usage limit.'],
            ]);
        }

        $subtotal = (int) ($cartSummary['totals']['subtotal'] ?? 0);

        if ($coupon->min_order_amount !== null && $subtotal < $coupon->min_order_amount) {
            throw ValidationException::withMessages([
                'coupon_code' => [sprintf(
                    'This coupon requires a minimum order of %s.',
                    Money::format($coupon->min_order_amount, $this->currencySymbol()),
                )],
            ]);
        }

        if ($coupon->user_restricted && ! $this->isUserPermitted($coupon, $user)) {
            // Deliberately identical to "invalid code" — confirming a coupon
            // exists but is restricted to someone else is a disclosure a
            // shopper guessing codes should not get.
            throw ValidationException::withMessages([
                'coupon_code' => ['That coupon code is not valid.'],
            ]);
        }

        if ($coupon->first_order_only && ! $this->isFirstOrder($user, $customerEmail)) {
            throw ValidationException::withMessages([
                'coupon_code' => ['This coupon is only valid on your first order.'],
            ]);
        }

        $this->assertWithinPerUserLimit($coupon, $user, $customerEmail);

        if ($this->eligibleAmount($coupon, $cartSummary) <= 0) {
            throw ValidationException::withMessages([
                'coupon_code' => ['None of the items in your cart are eligible for this coupon.'],
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertWithinPerUserLimit(Coupon $coupon, ?User $user, string $customerEmail): void
    {
        if ($coupon->per_user_limit === null) {
            return;
        }

        $used = CouponUsage::query()
            ->where('coupon_id', $coupon->getKey())
            ->forCustomer($user?->getKey(), $customerEmail)
            ->count();

        if ($used >= $coupon->per_user_limit) {
            throw ValidationException::withMessages([
                'coupon_code' => ['You have already used this coupon the maximum number of times.'],
            ]);
        }
    }

    /**
     * Whether a customer is on a user-restricted coupon's allow list.
     *
     * A guest can never satisfy a user-restricted coupon — the restriction
     * names accounts, and a guest has none to be named.
     */
    private function isUserPermitted(Coupon $coupon, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $coupon->users()->whereKey($user->getKey())->exists();
    }

    /**
     * Whether this customer has no previous revenue-bearing order.
     *
     * Cancelled and refunded orders do not count — a customer whose first
     * attempt was cancelled has not yet had the order the welcome discount was
     * meant to apply to. Matched by user id when signed in and by email
     * otherwise, so a first-order coupon means the same thing for a guest as
     * it does for an account.
     */
    private function isFirstOrder(?User $user, string $customerEmail): bool
    {
        $query = Order::query()->revenueBearing();

        if ($user !== null) {
            $query->where(fn ($q) => $q
                ->where('user_id', $user->getKey())
                ->orWhereRaw('LOWER(customer_email) = ?', [strtolower(trim($customerEmail))]));
        } else {
            $query->whereRaw('LOWER(customer_email) = ?', [strtolower(trim($customerEmail))]);
        }

        return ! $query->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Scope resolution
    |--------------------------------------------------------------------------
    */

    /**
     * The subtotal of the lines a coupon actually discounts.
     *
     * The whole-order subtotal when unrestricted; otherwise the sum of cart
     * lines that match the coupon's product or category scope, minus any
     * explicitly excluded line. A coupon that names both products and
     * categories matches a line satisfying either — "these specific items, or
     * anything in this category" is the ordinary reading of two scopes
     * configured together, not an intersection that a shopper would never
     * expect.
     *
     * @param  array<string, mixed>  $cartSummary
     */
    private function eligibleAmount(Coupon $coupon, array $cartSummary): int
    {
        if ($coupon->applies_to_all) {
            return (int) ($cartSummary['totals']['subtotal'] ?? 0);
        }

        $productRules = $coupon->products()->get(['products.id', 'coupon_product.is_excluded']);

        /*
         * The full category row, not a narrow column list.
         *
         * `expandCategoryRules()` below calls `subtreeIds()` for any row with
         * `includes_descendants` set, and that in turn needs `path`,
         * `parent_id`, and `depth` to compute the subtree — columns a select
         * limited to `id` would leave unretrieved and throw a
         * MissingAttributeException on first access.
         */
        $categoryRules = $coupon->categories()->get();

        if ($productRules->isEmpty() && $categoryRules->isEmpty()) {
            return 0;
        }

        $includedProductIds = $productRules->where('pivot.is_excluded', false)->pluck('id')->all();
        $excludedProductIds = $productRules->where('pivot.is_excluded', true)->pluck('id')->all();

        $includedCategoryIds = $this->expandCategoryRules($categoryRules->where('pivot.is_excluded', false));
        $excludedCategoryIds = $this->expandCategoryRules($categoryRules->where('pivot.is_excluded', true));

        $eligible = 0;

        foreach ($cartSummary['items'] as $line) {
            if (! $line['is_available']) {
                continue;
            }

            $productUuid = $line['product']['id'];
            $productId = Product::query()->where('uuid', $productUuid)->value('id');

            if ($productId === null) {
                continue;
            }

            if (in_array((int) $productId, $excludedProductIds, strict: true)) {
                continue;
            }

            $categoryId = Product::query()->whereKey($productId)->value('category_id');

            if ($categoryId !== null && in_array((int) $categoryId, $excludedCategoryIds, strict: true)) {
                continue;
            }

            $matches = in_array((int) $productId, $includedProductIds, strict: true)
                || ($categoryId !== null && in_array((int) $categoryId, $includedCategoryIds, strict: true));

            if ($matches) {
                $eligible += (int) $line['line_total'];
            }
        }

        return $eligible;
    }

    /**
     * Expand a set of category-coupon pivot rows into the full id set they
     * cover, honouring each row's own `includes_descendants` flag.
     *
     * @param  Collection<int, Category>  $categories
     * @return array<int, int>
     */
    private function expandCategoryRules(Collection $categories): array
    {
        $ids = [];

        foreach ($categories as $category) {
            $ids = array_merge(
                $ids,
                $category->pivot->includes_descendants ? $category->subtreeIds() : [(int) $category->getKey()],
            );
        }

        return array_values(array_unique($ids));
    }

    /**
     * @throws ValidationException
     */
    private function findUsable(string $code): Coupon
    {
        $coupon = Coupon::query()->byCode($code)->first();

        if ($coupon === null) {
            throw ValidationException::withMessages([
                'coupon_code' => ['That coupon code is not valid.'],
            ]);
        }

        return $coupon;
    }

    private function currencySymbol(): string
    {
        return (string) app(SettingsService::class)->get('business.currency_symbol', '$');
    }
}
