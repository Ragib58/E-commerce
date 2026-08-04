<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shopping carts, for both guests and signed-in customers.
 *
 * One table serves both, keyed by *either* `user_id` or `token`. The
 * alternative — a session/cookie cart for guests and a separate database cart
 * for members — means two storage engines, two sets of pricing logic, and a
 * merge step that has to reconcile shapes that were never the same. Here the
 * merge is an UPDATE of `user_id` plus a reconciliation of line items, and
 * every other code path is identical for both kinds of shopper.
 *
 * The guest `token` is an opaque random string held in an httpOnly cookie. It
 * is a bearer credential for a cart, so it is generated with a CSPRNG and is
 * long enough that guessing another shopper's cart is not feasible — a short
 * or sequential id would let anyone read and empty a stranger's basket.
 *
 * What this table deliberately does NOT store: money. There is no `subtotal`,
 * no `total`, no per-cart discount column. Every figure a shopper sees is
 * recomputed from the catalog on read, because a stored total is a number that
 * can disagree with the products it claims to describe — see CartService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();

            /*
             * The owner, once they sign in.
             *
             * Nullable: a guest cart exists before any account does. Cascades on
             * delete, because a cart has no meaning without its owner and
             * orphaning one would leave an unreachable row forever.
             */
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            /*
             * The guest credential. 64 hex characters from random_bytes(32).
             *
             * Retained after the cart is claimed by a user so an in-flight
             * request carrying the old cookie still resolves to the right cart
             * rather than silently creating an empty second one.
             */
            $table->string('token', 64)->nullable()->unique();

            /*
             * Coupon fields are a placeholder for the promotions phase.
             *
             * The code is stored but no discount is computed from it, and the
             * API says so explicitly rather than returning a zero that a client
             * might render as "coupon applied, £0 off". Storing it now means a
             * shopper who enters a code before promotions ship does not lose it
             * at checkout.
             */
            $table->string('coupon_code', 64)->nullable();

            /*
             * Housekeeping. `last_activity_at` is what a pruning job reads:
             * abandoned guest carts accumulate forever otherwise, and each one
             * holds rows that reference live products.
             */
            $table->timestamp('last_activity_at')->nullable()->index();

            $table->timestamps();

            // The two lookup paths, one per kind of shopper. `user_id` is not
            // unique: a customer can briefly have their old cart and a
            // just-merged guest cart in flight, and the service reconciles them.
            $table->index('user_id', 'carts_user_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
