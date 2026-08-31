<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockReservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Short-lived holds on stock, taken during checkout.
 *
 * ## The position this occupies
 *
 * A cart deliberately does not reserve — reserving at add-to-cart would let
 * anyone deny the catalog to everyone else by filling a basket, and shoppers sit
 * on carts for days. Checkout is different in the way that matters: it is
 * bounded, intentional, and short. A shopper who has entered an address and
 * chosen a payment method is minutes from placing, and losing the last unit at
 * the final click is the worst possible moment to find out.
 *
 * The hold is therefore taken late and expires fast. Expiry is what makes
 * granting it safe at all: an abandoned checkout releases its units without
 * anyone intervening, so a reservation can never permanently strand inventory.
 *
 * ## A reservation is not a decrement
 *
 * `products.stock` is untouched while a reservation is live. Available-to-sell
 * is `stock` minus live reservations, computed by {@see availableQuantity()}.
 * The authoritative decrement still happens exactly once, at placement, inside
 * InventoryService under a row lock.
 *
 * Keeping them separate preserves the inventory ledger's meaning: a
 * StockMovement records goods that actually moved, and writing one for a
 * checkout later abandoned would fill the ledger with sales that never happened
 * and corrupt every reconciliation built on it.
 *
 * ## Concurrency
 *
 * Reserving reads availability and then writes a row — the textbook
 * read-modify-write race. Two shoppers checking out the last unit would both
 * read "1 available" and both insert a hold. Every reserve below therefore runs
 * in a transaction that first locks the *stockable row*, so the second request
 * blocks until the first has committed its reservation and then sees it.
 *
 * The lock is on the product or variant rather than on the reservation rows
 * because there is nothing to lock until a reservation exists — the same reason
 * CartService locks the cart rather than the line.
 */
final class StockReservationService
{
    /**
     * How long a hold lasts.
     *
     * Long enough to fill in an address and a card without rushing; short
     * enough that an abandoned checkout does not strand the last unit for an
     * hour. Fifteen minutes is the figure most carts converge on, and it is a
     * constant here rather than a setting because changing it has consequences
     * (a longer window means more stranded stock) that an operator toggling a
     * field would not see.
     */
    private const HOLD_MINUTES = 15;

    /**
     * How much of a stockable may still be sold.
     *
     * `stock` minus live reservations. Null means unlimited — a digital product
     * or one on backorder — and is deliberately distinct from zero, which means
     * "tracked, and there are none".
     *
     * Mirrors CartService::availableQuantity, extended by the reservation
     * subtraction. The two agree on what "unlimited" means so a line the cart
     * calls available cannot be refused here for a different reason.
     */
    public function availableQuantity(
        Product $product,
        ?ProductVariant $variant = null,
        ?CheckoutSession $excluding = null,
    ): ?int {
        if (! $product->type->tracksInventory()) {
            return null;
        }

        if ($variant !== null) {
            if ($variant->allow_backorder) {
                return null;
            }

            $onHand = (int) $variant->stock;
        } else {
            if ($product->allow_backorder) {
                return null;
            }

            $onHand = (int) $product->effective_stock;
        }

        $held = $this->reservedQuantity(
            (int) $product->getKey(),
            $variant?->getKey() !== null ? (int) $variant->getKey() : null,
            $excluding,
        );

        return max(0, $onHand - $held);
    }

    /**
     * Units currently held by live reservations.
     *
     * `$excluding` omits one session's own holds. Without it a shopper
     * re-reading their own checkout would see the units they themselves are
     * holding counted against them, and a quantity they already reserved would
     * report as unavailable.
     */
    public function reservedQuantity(
        int $productId,
        ?int $variantId,
        ?CheckoutSession $excluding = null,
    ): int {
        return (int) StockReservation::query()
            ->live()
            ->forStockable($productId, $variantId)
            ->when(
                $excluding !== null,
                fn ($query) => $query->where(function ($inner) use ($excluding): void {
                    $inner->whereNull('checkout_session_id')
                        ->orWhere('checkout_session_id', '!=', $excluding->getKey());
                }),
            )
            ->sum('quantity');
    }

    /**
     * Take holds for every line of a checkout session's cart.
     *
     * Replaces any holds the session already had, so calling it again after the
     * cart changed is correct rather than additive — a shopper who returns to
     * the cart, halves a quantity, and comes back must not still be holding the
     * original amount.
     *
     * Runs as one transaction: partial reservation is worse than none, because
     * a shopper holding three of five lines has been told nothing useful while
     * still denying those three to everyone else.
     *
     * @return array<int, StockReservation>
     *
     * @throws ValidationException when a line cannot be held.
     */
    public function reserveForSession(CheckoutSession $session): array
    {
        $cart = $session->cart()->with('itemsForPricing')->first();

        if ($cart === null) {
            throw ValidationException::withMessages([
                'cart' => ['Your cart could not be found.'],
            ]);
        }

        $items = $cart->itemsForPricing()->get();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'cart' => ['Your cart is empty.'],
            ]);
        }

        return DB::transaction(function () use ($session, $items): array {
            // Released first, inside the same transaction, so the session's own
            // previous holds do not count against its new ones and a re-reserve
            // cannot fail against itself.
            $this->releaseForSession($session);

            $expiresAt = Carbon::now()->addMinutes(self::HOLD_MINUTES);
            $reservations = [];

            foreach ($items as $item) {
                $product = $item->product;
                $variant = $item->variant;

                if ($product === null) {
                    continue;
                }

                // Untracked lines need no hold: there is nothing finite to
                // hold, and writing a row would make availability arithmetic
                // subtract against an unlimited supply.
                if (! $product->type->tracksInventory()) {
                    continue;
                }

                if ($variant !== null ? $variant->allow_backorder : $product->allow_backorder) {
                    continue;
                }

                $reservations[] = $this->reserveLocked(
                    $product,
                    $variant,
                    (int) $item->quantity,
                    $session,
                    $expiresAt,
                );
            }

            return $reservations;
        });
    }

    /**
     * Take one hold, with the stockable row locked.
     *
     * Assumes it is already inside a transaction — every caller enters one.
     *
     * @throws ValidationException
     */
    private function reserveLocked(
        Product $product,
        ?ProductVariant $variant,
        int $quantity,
        CheckoutSession $session,
        Carbon $expiresAt,
    ): StockReservation {
        /*
         * The lock that closes the race.
         *
         * Re-read under `lockForUpdate` before availability is computed, so a
         * concurrent checkout of the same item blocks here until this one has
         * committed its reservation — and then reads a figure that includes it.
         * Without this, two shoppers both read "1 available" and both hold the
         * last unit.
         */
        $lockedVariant = null;
        $lockedProduct = null;

        if ($variant !== null) {
            $lockedVariant = ProductVariant::query()
                ->lockForUpdate()
                ->with('product')
                ->find($variant->getKey());
        } else {
            $lockedProduct = Product::query()
                ->lockForUpdate()
                ->find($product->getKey());
        }

        $available = $this->availableQuantity(
            $lockedProduct ?? $lockedVariant?->product ?? $product,
            $lockedVariant,
            $session,
        );

        if ($available !== null && $quantity > $available) {
            $name = $variant !== null
                ? $product->name.' — '.$variant->buildName()
                : $product->name;

            throw ValidationException::withMessages([
                'items' => [$available > 0
                    ? sprintf('Only %d of "%s" remain in stock.', $available, $name)
                    : sprintf('"%s" is out of stock.', $name)],
            ]);
        }

        return StockReservation::query()->create([
            'product_id' => $product->getKey(),
            'product_variant_id' => $variant?->getKey(),
            'quantity' => $quantity,
            'checkout_session_id' => $session->getKey(),
            'status' => StockReservation::STATUS_ACTIVE,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Release a session's holds.
     *
     * Marked released rather than deleted: a reservation that vanished tells
     * nobody why the units a shopper expected were not there.
     */
    public function releaseForSession(CheckoutSession $session): int
    {
        return StockReservation::query()
            ->where('checkout_session_id', $session->getKey())
            ->where('status', StockReservation::STATUS_ACTIVE)
            ->update([
                'status' => StockReservation::STATUS_RELEASED,
                'released_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * Mark a session's holds as converted into a placed order.
     *
     * Called inside the placing transaction, immediately after the stock has
     * actually been decremented. The order of those two operations matters: a
     * reservation released *before* the decrement reopens the window it existed
     * to close, and another shopper can take the unit in between.
     */
    public function commitForSession(CheckoutSession $session, Order $order): int
    {
        return StockReservation::query()
            ->where('checkout_session_id', $session->getKey())
            ->where('status', StockReservation::STATUS_ACTIVE)
            ->update([
                'status' => StockReservation::STATUS_COMMITTED,
                'order_id' => $order->getKey(),
                'released_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * Extend a session's holds.
     *
     * A shopper still actively filling in a form should not lose their stock to
     * a timer. Only live holds are extended — reviving an expired one would
     * take units that another shopper may already have been promised in the
     * interval.
     */
    public function extendForSession(CheckoutSession $session): int
    {
        return StockReservation::query()
            ->live()
            ->where('checkout_session_id', $session->getKey())
            ->update([
                'expires_at' => Carbon::now()->addMinutes(self::HOLD_MINUTES),
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * Mark expired holds as released.
     *
     * Housekeeping only. Availability already ignores expired rows in SQL — see
     * StockReservation::scopeLive — so stock is never stranded waiting for this
     * to run. If it were the mechanism rather than the tidy-up, availability
     * would depend on how recently a scheduled job last ran.
     */
    public function sweepExpired(?Carbon $at = null): int
    {
        $now = $at ?? Carbon::now();

        return StockReservation::query()
            ->sweepable($now)
            ->update([
                'status' => StockReservation::STATUS_RELEASED,
                'released_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /**
     * When a session's holds lapse, or null when it holds none.
     *
     * Rendered as a countdown at checkout, so the deadline a shopper is racing
     * is one they can actually see.
     */
    public function expiresAtFor(CheckoutSession $session): ?Carbon
    {
        $earliest = StockReservation::query()
            ->live()
            ->where('checkout_session_id', $session->getKey())
            ->min('expires_at');

        return $earliest !== null ? Carbon::parse($earliest) : null;
    }

    public static function holdMinutes(): int
    {
        return self::HOLD_MINUTES;
    }
}
