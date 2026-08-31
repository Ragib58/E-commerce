<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A checkout in progress.
 *
 * ## Why the server holds this at all
 *
 * The alternative is a client that collects all seven steps and posts them
 * together. That makes the step sequence a frontend concern, and a frontend
 * concern is not a constraint: a crafted request posts straight to "place
 * order" having skipped shipping selection, and the order is created with a
 * null shipping cost. Holding the partial state server-side means the sequence
 * is enforced where it cannot be bypassed — see App\Enums\CheckoutStep.
 *
 * It also makes checkout **resumable**. A shopper who closes the tab at the
 * payment step returns to it as they left it, because each step's answer was
 * persisted when given rather than held in a form until the end. Cart
 * abandonment at checkout is expensive, and losing a half-filled address form
 * to a dropped connection is a self-inflicted share of it.
 *
 * ## What it deliberately does not store
 *
 * No prices, and no totals. The session holds *choices* — an address, a
 * shipping method id, a payment method — and every figure is recomputed from
 * the catalog and the shipping method row on each read, exactly as the cart
 * does. A total persisted here at step 4 and trusted at step 7 would be a
 * three-step window in which the catalog could change underneath it, and a
 * writable surface a crafted request could aim at.
 *
 * ## Lifetime
 *
 * Sessions expire. An abandoned one holds no stock (see the reservations table
 * for what does) but it does hold personal data — a name, an address, a phone
 * number — and keeping that indefinitely for a checkout nobody completed is a
 * liability rather than an asset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_sessions', function (Blueprint $table): void {
            $table->id();

            /*
             * The public handle the client carries between steps. A bearer
             * credential for a checkout containing an address and a phone
             * number, so it is generated with a CSPRNG and is long enough that
             * guessing another shopper's session is not feasible.
             */
            $table->string('token', 64)->unique();

            /*
             * The cart being checked out.
             *
             * cascadeOnDelete: a checkout without its cart has nothing to
             * price, and leaving the row behind would let a completed or
             * cleared cart's session linger.
             */
            $table->foreignId('cart_id')
                ->constrained()
                ->cascadeOnDelete();

            /** Null for guest checkout, which is a first-class path here. */
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            /*
             * The collected answers, one key per step. Shape:
             *
             *   customer:                  {name, email, phone}
             *   shipping_address:          {first_name, ..., country}
             *   billing_address:           {...} | null
             *   billing_same_as_shipping:  bool
             *   shipping_method_id:        int
             *   payment_method:            string
             *   customer_note:             string|null
             *   reviewed_at:               ISO-8601 timestamp
             *
             * JSON rather than columns because the steps are a workflow, not an
             * entity: a store that adds a "gift options" step should not need a
             * migration, and half the columns would be null at any moment
             * anyway.
             */
            $table->json('data')->nullable();

            /** The furthest step reached, for resuming and for the progress bar. */
            $table->string('current_step', 32)->default('customer');

            /*
             * Set once the session has produced an order, so a replayed "place
             * order" is answered with the existing order rather than a second
             * one. The unique index is the guarantee; see also
             * `orders.idempotency_key`, which closes the same window from the
             * other side.
             */
            $table->foreignId('order_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->timestamp('completed_at')->nullable();

            /** When this session stops being resumable. See the class docblock. */
            $table->timestamp('expires_at')->nullable()->index();

            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index('cart_id', 'checkout_sessions_cart_index');
            $table->index('user_id', 'checkout_sessions_user_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_sessions');
    }
};
