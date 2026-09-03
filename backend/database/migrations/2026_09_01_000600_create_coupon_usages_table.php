<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An append-only record of every coupon redemption.
 *
 * `coupons.used_count` answers "has this coupon hit its global limit" without
 * a COUNT over a growing table. This table answers the question a counter
 * cannot: "has *this customer* hit *their* limit", and "which orders used this
 * code" for support and finance.
 *
 * ## Why per-user limits need a real table, not a counter column
 *
 * A per-user counter would live on a (coupon, user) pivot, which is fine right
 * up until a guest uses the coupon. A guest has no user row to attach a counter
 * to, and the brief's per-user limit still has to mean something for them — so
 * the limit is enforced by counting matching rows here, keyed by user id when
 * one exists and by email otherwise. One mechanism, both kinds of shopper,
 * exactly the pattern this project already uses for guest order lookup.
 *
 * ## Snapshot, like everything else that touches money
 *
 * `discount_amount` is what was actually deducted, captured at redemption.
 * Recomputing it later from the coupon's current rules would restate history
 * the moment a percentage or a cap changes — the same reasoning that makes an
 * order line snapshot its price rather than read the catalog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_usages', function (Blueprint $table): void {
            $table->id();

            /*
             * restrictOnDelete rather than cascade: a coupon with redemption
             * history behind it must not be deletable in a way that silently
             * erases the record of a discount an order actually received.
             * Coupons are archived (is_active = false), not deleted, once used.
             */
            $table->foreignId('coupon_id')
                ->constrained()
                ->restrictOnDelete();

            /*
             * restrictOnDelete for the same reason: an order's discount history
             * must survive as long as the order does, and orders themselves are
             * never hard-deleted — see the orders migration.
             */
            $table->foreignId('order_id')
                ->constrained()
                ->restrictOnDelete();

            /*
             * Nullable: a guest redemption has no account. nullOnDelete rather
             * than restrict — deleting a customer account must not be blocked
             * by, or erase, the record that their order used a coupon.
             */
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
             * The redeemer's identity for a guest, so the per-user limit has
             * something to key on. Lower-cased at write time, matching the
             * comparison guest order lookup already uses.
             */
            $table->string('customer_email', 191);

            /** What the code was at redemption, kept even if it is later renamed. */
            $table->string('coupon_code', 64);

            /** Minor units actually deducted. See the class docblock. */
            $table->unsignedBigInteger('discount_amount');

            // No updated_at: a redemption is a fact about a moment, and rows
            // are never revised — a refund does not undo the redemption, it is
            // a separate event on the order.
            $table->timestamp('created_at')->nullable();

            $table->index(['coupon_id', 'user_id'], 'coupon_usages_user_index');
            $table->index(['coupon_id', 'customer_email'], 'coupon_usages_email_index');
            $table->index('order_id', 'coupon_usages_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_usages');
    }
};
