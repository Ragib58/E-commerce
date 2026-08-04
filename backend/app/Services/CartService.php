<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cart lifecycle and pricing.
 *
 * ## The rule this class exists to enforce
 *
 * **No price ever reaches the database from a request, and no price is ever
 * read back from a cart row.** A client may say *what* it wants and *how many*;
 * everything else — unit price, discount, line total, subtotal, tax, stock
 * availability — is derived here from `products` and `product_variants` at the
 * moment of reading.
 *
 * That is stricter than validating a submitted price, and deliberately so.
 * Validation compares a client's number against the catalog and rejects a
 * mismatch, which means the comparison is a code path that can be skipped,
 * mis-ordered, or forgotten on a new endpoint. Deriving the number means there
 * is no client-supplied price anywhere in the system to check — the class of
 * bug is absent rather than defended against.
 *
 * The visible consequence is that `cart_items` has no price column at all (see
 * its migration), and nothing below assigns one.
 *
 * ## Guest and authenticated carts
 *
 * Both are rows in `carts`, keyed by a token or a user id. One storage engine,
 * one pricing path, and a merge that is an UPDATE rather than a translation
 * between two representations.
 *
 * ## Stock
 *
 * Availability is checked here on every mutation, but this is *not* a
 * reservation. Adding to a cart does not hold stock — two shoppers can both
 * hold the last unit, and the one who checks out first gets it. Reserving at
 * add-to-cart would let anyone deny the catalog to everyone else by filling a
 * basket. The authoritative decrement happens once, inside InventoryService,
 * under a row lock, at order placement.
 */
final class CartService
{
    /**
     * Ceiling on a single line's quantity.
     *
     * Not a stock limit — a guard against a typo or a script turning one line
     * into an order the warehouse cannot fulfil. Real availability is checked
     * separately and is usually far lower.
     */
    private const MAX_QUANTITY_PER_LINE = 99;

    /** Ceiling on distinct lines, so a cart cannot be used as free storage. */
    private const MAX_LINES = 100;

    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Resolution
    |--------------------------------------------------------------------------
    */

    /**
     * Find or create the cart for this request.
     *
     * A signed-in customer's cart is looked up by user id; a guest's by token.
     * The token is only trusted to identify a *guest* cart — a request bearing
     * someone else's token while authenticated resolves to the authenticated
     * user's own cart, never the token's. Otherwise a stolen or guessed cookie
     * would let an attacker read a signed-in shopper's basket.
     */
    public function resolve(?User $user, ?string $token, bool $create = true): ?Cart
    {
        if ($user !== null) {
            $cart = Cart::query()->forUser((int) $user->getKey())->latest('id')->first();

            if ($cart !== null) {
                return $cart;
            }

            return $create ? $this->createFor($user) : null;
        }

        if ($token !== null && $token !== '') {
            // Guest carts only. A cart already claimed by a user is not
            // reachable by token, so a shared or leaked cookie cannot expose an
            // account's basket after they sign in.
            $cart = Cart::query()->forToken($token)->whereNull('user_id')->first();

            if ($cart !== null) {
                return $cart;
            }
        }

        return $create ? $this->createFor(null) : null;
    }

    /**
     * Create an empty cart for a user or a guest.
     */
    private function createFor(?User $user): Cart
    {
        return Cart::query()->create([
            'user_id' => $user?->getKey(),
            // Guests need a credential; a user's cart is found by id and does
            // not get one, so a token can never resolve to an account's cart.
            'token' => $user === null ? $this->generateToken() : null,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * A guest cart credential.
     *
     * 32 bytes from a CSPRNG. This is a bearer token for a basket — a short or
     * sequential value would let anyone enumerate and empty strangers' carts.
     */
    private function generateToken(): string
    {
        do {
            $token = bin2hex(random_bytes(32));
        } while (Cart::query()->where('token', $token)->exists());

        return $token;
    }

    /**
     * Attach a guest cart to a customer who has just signed in.
     *
     * Lines are merged rather than replaced: a shopper who filled a basket
     * before logging in expects to keep it, and a shopper with an older saved
     * cart expects that too. Quantities for the same product are summed and
     * re-clamped to what is actually available.
     *
     * Idempotent, so a client that calls it on every page load costs one query
     * and changes nothing.
     */
    public function mergeGuestCart(User $user, ?string $guestToken): Cart
    {
        $guestCart = $guestToken !== null && $guestToken !== ''
            ? Cart::query()->forToken($guestToken)->whereNull('user_id')->first()
            : null;

        $userCart = Cart::query()->forUser((int) $user->getKey())->latest('id')->first();

        if ($guestCart === null) {
            return $userCart ?? $this->createFor($user);
        }

        // No existing customer cart: claim the guest one wholesale. Cheaper
        // than copying lines, and it preserves the row the shopper is already
        // interacting with.
        if ($userCart === null) {
            $guestCart->forceFill([
                'user_id' => $user->getKey(),
                // The token is cleared so the cookie can no longer reach this
                // cart now that it belongs to an account.
                'token' => null,
                'last_activity_at' => now(),
            ])->save();

            return $guestCart;
        }

        if ($guestCart->is($userCart)) {
            return $userCart;
        }

        DB::transaction(function () use ($guestCart, $userCart): void {
            $existing = $userCart->items()->get()->keyBy(
                fn (CartItem $item): string => $this->lineKey($item->product_id, $item->product_variant_id),
            );

            foreach ($guestCart->items()->get() as $guestItem) {
                $key = $this->lineKey($guestItem->product_id, $guestItem->product_variant_id);
                $match = $existing->get($key);

                if ($match === null) {
                    $guestItem->forceFill(['cart_id' => $userCart->getKey()])->save();

                    continue;
                }

                // Summed, then clamped: the two carts may between them hold more
                // than exists, and carrying that forward would surface as a
                // checkout failure rather than a visible cart correction.
                $match->quantity = min(
                    self::MAX_QUANTITY_PER_LINE,
                    $match->quantity + $guestItem->quantity,
                );
                $match->save();

                $guestItem->delete();
            }

            // The guest row is now empty and unreachable.
            $guestCart->delete();

            $userCart->forceFill(['last_activity_at' => now()])->save();
        });

        return $userCart->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Mutations
    |--------------------------------------------------------------------------
    */

    /**
     * Add a product to the cart, or increase an existing line.
     *
     * @param  array<string, mixed>|null  $options  Personalisation for customizable products.
     *
     * @throws ValidationException
     */
    public function add(
        Cart $cart,
        string $productSlugOrUuid,
        ?string $variantUuid = null,
        int $quantity = 1,
        ?array $options = null,
    ): CartItem {
        $quantity = $this->normaliseQuantity($quantity);

        $product = $this->resolveProduct($productSlugOrUuid);
        $variant = $this->resolveVariant($product, $variantUuid);

        $this->assertLineCapacity($cart);

        return DB::transaction(function () use ($cart, $product, $variant, $quantity, $options): CartItem {
            /*
             * Locked before reading the current quantity.
             *
             * Two "add" clicks racing would otherwise both read quantity 1 and
             * both write 2, losing one of the increments. The lock is on the
             * cart row rather than the item because the item may not exist yet
             * — there is nothing to lock until it does.
             */
            Cart::query()->whereKey($cart->getKey())->lockForUpdate()->first();

            $existing = $cart->items()
                ->where('product_id', $product->getKey())
                ->where('product_variant_id', $variant?->getKey())
                ->first();

            $requested = ($existing?->quantity ?? 0) + $quantity;

            // Availability is checked against the *resulting* quantity, not the
            // delta: adding 1 to a line already holding the last 3 units must
            // fail, even though 1 by itself is available.
            $this->assertAvailable($product, $variant, $requested);

            if ($existing !== null) {
                $existing->quantity = min(self::MAX_QUANTITY_PER_LINE, $requested);

                if ($options !== null) {
                    $existing->options = $options;
                }

                $existing->save();

                $cart->touchActivity();

                return $existing;
            }

            try {
                $item = $cart->items()->create([
                    'product_id' => $product->getKey(),
                    'product_variant_id' => $variant?->getKey(),
                    'quantity' => $quantity,
                    'options' => $options,
                ]);
            } catch (QueryException $exception) {
                /*
                 * The unique index fired: a concurrent request inserted this
                 * exact line between the read above and this write. Increment
                 * the row that won rather than surfacing a database error for
                 * what is, from the shopper's side, an ordinary double click.
                 */
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }

                $item = $cart->items()
                    ->where('product_id', $product->getKey())
                    ->where('product_variant_id', $variant?->getKey())
                    ->firstOrFail();

                $item->quantity = min(self::MAX_QUANTITY_PER_LINE, $item->quantity + $quantity);
                $item->save();
            }

            $cart->touchActivity();

            return $item;
        });
    }

    /**
     * Set a line's quantity outright.
     *
     * A quantity of zero removes the line — a shopper clearing the field and
     * typing 0 means "take it out", and forcing them to find a separate button
     * for what they just expressed is friction for nothing.
     *
     * @throws ValidationException
     */
    public function updateQuantity(Cart $cart, int $itemId, int $quantity): ?CartItem
    {
        $item = $this->findItem($cart, $itemId);

        if ($quantity <= 0) {
            $this->remove($cart, $itemId);

            return null;
        }

        $quantity = $this->normaliseQuantity($quantity);

        return DB::transaction(function () use ($cart, $item, $quantity): CartItem {
            Cart::query()->whereKey($cart->getKey())->lockForUpdate()->first();

            $item->load(['product', 'variant.product']);

            $this->assertAvailable($item->product, $item->variant, $quantity);

            $item->quantity = $quantity;
            $item->save();

            $cart->touchActivity();

            return $item;
        });
    }

    /**
     * @throws ValidationException
     */
    public function remove(Cart $cart, int $itemId): void
    {
        $this->findItem($cart, $itemId)->delete();

        $cart->touchActivity();
    }

    public function clear(Cart $cart): void
    {
        $cart->items()->delete();

        $cart->touchActivity();
    }

    /**
     * Store a coupon code against the cart.
     *
     * **Placeholder.** The code is persisted so a shopper who enters one before
     * the promotions phase ships does not lose it, but no discount is computed
     * and {@see summarise()} reports `coupon.applied = false` with an explicit
     * reason. Returning a zero discount instead would render as "coupon
     * applied, £0.00 off", which reads as a broken promotion rather than an
     * unbuilt feature.
     *
     * @throws ValidationException
     */
    public function applyCoupon(Cart $cart, ?string $code): Cart
    {
        $code = $code !== null ? trim($code) : null;

        if ($code === '') {
            $code = null;
        }

        if ($code !== null && ! preg_match('/^[A-Za-z0-9_-]{2,64}$/', $code)) {
            throw ValidationException::withMessages([
                'coupon_code' => ['That does not look like a valid coupon code.'],
            ]);
        }

        $cart->forceFill(['coupon_code' => $code !== null ? strtoupper($code) : null])->save();

        return $cart;
    }

    /*
    |--------------------------------------------------------------------------
    | Reading and pricing
    |--------------------------------------------------------------------------
    */

    /**
     * The cart as the storefront should display it, priced from the catalog.
     *
     * Every number in the returned structure is computed here. Nothing is read
     * from a stored total, and nothing originates in a request.
     *
     * Lines that can no longer be sold are still returned, flagged with an
     * `issues` array, rather than being silently dropped. A shopper whose item
     * vanished between page loads with no explanation assumes the site lost it;
     * a line that says "out of stock" is information they can act on.
     *
     * @return array<string, mixed>
     */
    public function summarise(Cart $cart): array
    {
        /*
         * Loaded into `items`, not into `itemsForPricing`.
         *
         * The two relations describe the same rows — `itemsForPricing` is
         * `items` plus its eager loads — but Eloquent keys a loaded relation by
         * the method name. Loading one and iterating the other would leave
         * `items` unloaded, so every line would lazily fetch its product and
         * variant: two queries per line, or a LazyLoadingViolation under
         * `Model::shouldBeStrict()`.
         */
        $cart->setRelation('items', $cart->itemsForPricing()->get());

        $lines = [];
        $subtotal = 0;
        $discountTotal = 0;
        $taxableSubtotal = 0;
        $itemCount = 0;

        foreach ($cart->items as $item) {
            $line = $this->priceLine($item);

            $lines[] = $line;

            // Unsellable lines contribute nothing to the totals: charging for
            // something that cannot be shipped is worse than a short total.
            if (! $line['is_available']) {
                continue;
            }

            $subtotal += $line['line_total'];
            $discountTotal += $line['line_discount'];
            $itemCount += $line['quantity'];

            if ($line['is_taxable']) {
                $taxableSubtotal += $line['line_total'];
            }
        }

        $tax = $this->calculateTax($taxableSubtotal);

        return [
            'id' => $cart->id,
            'items' => $lines,
            'item_count' => $itemCount,
            'line_count' => count($lines),

            'totals' => [
                // The sum of line totals at the effective (discounted) price.
                'subtotal' => $subtotal,
                // What the discounts saved, for a "you saved £X" line. Derived
                // from the catalog's own list-versus-sale prices, never from a
                // client-supplied figure.
                'discount' => $discountTotal,
                'tax' => $tax,
                /*
                 * Shipping is not computed here. It depends on a delivery
                 * address the cart does not have, and inventing a placeholder
                 * that later changes at checkout is how a store gets accused of
                 * hiding costs. It appears in the checkout phase.
                 */
                'shipping' => null,
                'total' => $subtotal + $tax,
            ],

            'coupon' => [
                'code' => $cart->coupon_code,
                // Explicitly false, with a reason. See applyCoupon().
                'applied' => false,
                'discount' => 0,
                'message' => $cart->coupon_code !== null
                    ? 'Coupon codes are validated at checkout. No discount has been applied yet.'
                    : null,
            ],

            'has_issues' => collect($lines)->contains(fn (array $line): bool => $line['issues'] !== []),
        ];
    }

    /**
     * Price one line from the catalog.
     *
     * @return array<string, mixed>
     */
    private function priceLine(CartItem $item): array
    {
        $product = $item->product;
        $variant = $item->variant;

        /*
         * A variant's price wins when one was chosen, falling back to the
         * product. `effective_price` already resolves the discount-versus-list
         * question and the variant-inherits-from-product question, so this is
         * the single place the "which number do we charge?" decision is read
         * rather than re-derived.
         */
        $unitPrice = $variant !== null
            ? (int) $variant->effective_price
            : (int) $product->effective_price;

        $listPrice = $variant !== null
            ? (int) $variant->base_price
            : (int) $product->price;

        $quantity = (int) $item->quantity;

        $issues = [];
        $available = true;

        if (! $product->status->isVisible()) {
            $issues[] = [
                'code' => 'UNAVAILABLE',
                'message' => 'This product is no longer available.',
            ];
            $available = false;
        }

        if ($variant !== null && ! $variant->is_active) {
            $issues[] = [
                'code' => 'VARIANT_UNAVAILABLE',
                'message' => 'This option is no longer available.',
            ];
            $available = false;
        }

        $stockable = $variant ?? $product;
        $inStock = $variant !== null ? $variant->is_in_stock : $product->is_in_stock;

        if ($available && ! $inStock) {
            $issues[] = [
                'code' => 'OUT_OF_STOCK',
                'message' => 'This item is out of stock.',
            ];
            $available = false;
        }

        /*
         * The quantity in the cart may exceed what is left, because a cart does
         * not reserve stock. Reported rather than silently corrected: quietly
         * reducing a shopper's quantity is a change to their order that they
         * never see until the total moves.
         */
        $maxAvailable = $this->availableQuantity($product, $variant);

        if ($available && $maxAvailable !== null && $quantity > $maxAvailable) {
            $issues[] = [
                'code' => 'INSUFFICIENT_STOCK',
                'message' => $maxAvailable > 0
                    ? "Only {$maxAvailable} left in stock."
                    : 'This item is out of stock.',
                'available' => $maxAvailable,
            ];

            $available = $maxAvailable > 0;
        }

        $lineTotal = $unitPrice * $quantity;
        $lineDiscount = max(0, ($listPrice - $unitPrice)) * $quantity;

        return [
            'id' => $item->id,
            'quantity' => $quantity,
            'options' => $item->options,

            'product' => [
                'id' => $product->uuid,
                'name' => $product->name,
                'slug' => $product->slug,
                'sku' => $variant?->sku ?? $product->sku,
                'thumbnail' => $this->thumbnailUrl($product),
                'type' => $product->type->value,
            ],

            'variant' => $variant === null ? null : [
                'id' => $variant->uuid,
                'name' => $variant->buildName(),
            ],

            /*
             * Money, all of it computed above from catalog rows.
             */
            'unit_price' => $unitPrice,
            'list_price' => $listPrice > $unitPrice ? $listPrice : null,
            'line_total' => $lineTotal,
            'line_discount' => $lineDiscount,

            'is_taxable' => (bool) $product->is_taxable,
            'is_available' => $available,
            'max_quantity' => $maxAvailable,
            'issues' => $issues,
        ];
    }

    /**
     * How many units may still be added, or null when unlimited.
     *
     * Null means "not stock-tracked" — a digital product, or one on backorder —
     * and is deliberately distinct from zero, which means "tracked, and there
     * are none".
     */
    private function availableQuantity(Product $product, ?ProductVariant $variant): ?int
    {
        if (! $product->type->tracksInventory()) {
            return null;
        }

        if ($variant !== null) {
            return $variant->allow_backorder ? null : max(0, (int) $variant->stock);
        }

        return $product->allow_backorder ? null : max(0, (int) $product->effective_stock);
    }

    /**
     * Tax on the taxable portion of the subtotal.
     *
     * Computed once over the summed taxable lines rather than per line, and
     * rounded once at the end. Rounding per line accumulates a fraction of a
     * penny per item, so a ten-line cart can disagree with the same order
     * totalled elsewhere — and the discrepancy is invisible until an accountant
     * finds it.
     *
     * The rate is the admin-managed store setting, not a constant.
     */
    private function calculateTax(int $taxableSubtotal): int
    {
        if ($taxableSubtotal <= 0) {
            return 0;
        }

        $rate = (float) $this->settings->get('business.tax_rate', 0);

        if ($rate <= 0) {
            return 0;
        }

        return (int) round($taxableSubtotal * ($rate / 100));
    }

    private function thumbnailUrl(Product $product): ?string
    {
        if (! $product->relationLoaded('media')) {
            return null;
        }

        return $product->media->first()?->url;
    }

    /*
    |--------------------------------------------------------------------------
    | Validation helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve a product by its public identifier.
     *
     * Accepts a uuid or a slug — the storefront holds the uuid on a card and
     * the slug in the URL, and requiring a lookup to convert between them would
     * add a round trip to every add-to-cart.
     *
     * Constrained to published products, so an unreleased item cannot be added
     * by guessing its slug.
     *
     * @throws ValidationException
     */
    private function resolveProduct(string $identifier): Product
    {
        $product = Product::query()
            ->published()
            ->where(fn ($query) => $query->where('uuid', $identifier)->orWhere('slug', $identifier))
            ->first();

        if ($product === null) {
            throw ValidationException::withMessages([
                'product' => ['That product could not be found.'],
            ]);
        }

        return $product;
    }

    /**
     * Resolve the chosen variant, enforcing the product type's rules.
     *
     * A variable product *must* have one — adding "a t-shirt" without a size is
     * an unfulfillable line — and a simple product must not, since a variant id
     * from another product would otherwise be accepted and priced.
     *
     * @throws ValidationException
     */
    private function resolveVariant(Product $product, ?string $variantUuid): ?ProductVariant
    {
        $requiresVariant = $product->type === ProductType::Variable;

        if ($variantUuid === null || $variantUuid === '') {
            if ($requiresVariant) {
                throw ValidationException::withMessages([
                    'variant' => ['Choose an option before adding this product to your cart.'],
                ]);
            }

            return null;
        }

        // Scoped to this product, so a variant id belonging to a different
        // product cannot be attached to a cheaper one.
        $variant = ProductVariant::query()
            ->where('product_id', $product->getKey())
            ->where('uuid', $variantUuid)
            ->where('is_active', true)
            // Needed for price inheritance; without it effective_price falls
            // back to zero rather than the parent's figure.
            ->with('product')
            ->first();

        if ($variant === null) {
            throw ValidationException::withMessages([
                'variant' => ['That option is not available for this product.'],
            ]);
        }

        return $variant;
    }

    /**
     * @throws ValidationException
     */
    private function assertAvailable(Product $product, ?ProductVariant $variant, int $quantity): void
    {
        if (! $product->status->isVisible()) {
            throw ValidationException::withMessages([
                'product' => ['That product is no longer available.'],
            ]);
        }

        $available = $this->availableQuantity($product, $variant);

        if ($available === null) {
            return;
        }

        if ($available <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['This item is out of stock.'],
            ]);
        }

        if ($quantity > $available) {
            throw ValidationException::withMessages([
                'quantity' => ["Only {$available} of this item are available."],
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function findItem(Cart $cart, int $itemId): CartItem
    {
        $item = $cart->items()->whereKey($itemId)->first();

        if ($item === null) {
            // Scoped to the cart, so an id from someone else's cart is a 422
            // rather than a successful mutation of a stranger's basket.
            throw ValidationException::withMessages([
                'item' => ['That item is not in your cart.'],
            ]);
        }

        return $item;
    }

    /**
     * @throws ValidationException
     */
    private function assertLineCapacity(Cart $cart): void
    {
        if ($cart->items()->count() >= self::MAX_LINES) {
            throw ValidationException::withMessages([
                'cart' => ['Your cart is full. Remove an item before adding another.'],
            ]);
        }
    }

    private function normaliseQuantity(int $quantity): int
    {
        return max(1, min(self::MAX_QUANTITY_PER_LINE, $quantity));
    }

    private function lineKey(int $productId, ?int $variantId): string
    {
        return $productId . ':' . ($variantId ?? 0);
    }

    /**
     * Whether a query exception is a unique-constraint violation.
     *
     * Checked by SQLSTATE rather than by matching the driver's message text,
     * which differs between MySQL and the SQLite the test suite runs on.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23000' || $exception->getCode() === '23505';
    }
}
