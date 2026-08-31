<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short-lived holds on stock during checkout.
 *
 * ## Why this exists when the cart deliberately does not reserve
 *
 * A cart does *not* hold stock, on purpose: reserving at add-to-cart would let
 * anyone deny the catalog to everyone else by filling a basket, and shoppers sit
 * on carts for days. Checkout is different in exactly the way that matters — it
 * is bounded, intentional, and short. A shopper who has entered an address and
 * chosen a payment method is minutes from placing, and having the last unit sold
 * out from under them at the final click is the worst moment to discover it.
 *
 * So the hold is taken late and expires fast. `expires_at` is what makes it safe
 * to grant: an abandoned checkout releases its units automatically, so a
 * reservation can never permanently strand inventory the way a cart-level one
 * would.
 *
 * ## What a reservation is not
 *
 * It is **not** a stock decrement. `products.stock` is untouched while a
 * reservation is live; the authoritative decrement still happens once, inside
 * InventoryService, under a row lock, at placement. This table records *intent
 * to buy*, and available-to-sell is computed as `stock` minus live reservations.
 *
 * Keeping them separate is what preserves the inventory ledger's meaning: a
 * StockMovement records goods that actually moved, and writing one for a
 * checkout that is later abandoned would fill the ledger with sales that never
 * happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->id();

            /*
             * What is held. A variant when one was chosen, the product
             * otherwise — mirroring how a cart line identifies its stockable.
             */
            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('product_variant_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedInteger('quantity');

            /*
             * Who holds it.
             *
             * The checkout session, normally. cascadeOnDelete so a deleted or
             * pruned session cannot leave an orphaned hold that nothing will
             * ever release — the failure mode that turns a reservation system
             * into a slow inventory leak.
             */
            $table->foreignId('checkout_session_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            /*
             * Set once the reservation has been converted into a placed order's
             * stock decrement. Retained briefly rather than deleted so the
             * conversion is auditable, then pruned.
             */
            $table->foreignId('order_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            /**
             * `active`, `committed`, or `released`.
             *
             * A status rather than deleting the row on release: a reservation
             * that vanished tells you nothing about why the units a shopper
             * expected were not there.
             */
            $table->string('status', 16)->default('active');

            /*
             * When the hold lapses. The safety valve that makes reserving at
             * all defensible — see the class docblock.
             */
            $table->timestamp('expires_at')->index();

            $table->timestamp('released_at')->nullable();

            $table->timestamps();

            /*
             * The availability query: live holds on one stockable.
             *
             * Composite and ordered (stockable, status, expiry) because the
             * question is always "how much of *this item* is held *right now*",
             * and this index answers it without touching the table.
             */
            $table->index(
                ['product_id', 'product_variant_id', 'status', 'expires_at'],
                'stock_reservations_availability_index',
            );

            $table->index('checkout_session_id', 'stock_reservations_session_index');
            $table->index('order_id', 'stock_reservations_order_index');

            // The sweeper's query: expired holds still marked active.
            $table->index(['status', 'expires_at'], 'stock_reservations_sweep_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
