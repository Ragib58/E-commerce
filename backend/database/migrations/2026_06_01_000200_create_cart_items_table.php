<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lines within a cart.
 *
 * A line stores **what** was chosen and **how many** — never what it costs.
 * There is no `price`, `subtotal`, or `discount` column, and that absence is
 * the single most important decision in this table.
 *
 * A stored price is a claim that can drift from the catalog. If a shopper adds
 * an item at £20 and the price rises to £25 before checkout, a persisted £20 is
 * either an honoured promise nobody authorised or a stale number that silently
 * corrects itself at an unpredictable moment. Worse, a price column is a
 * writable surface: any endpoint that accepts a line item becomes a place where
 * a crafted request can name its own price. Recomputing from `products` and
 * `product_variants` on every read means the figure a shopper sees is, by
 * construction, the figure the catalog says — and there is nothing to tamper
 * with.
 *
 * The trade-off is real and accepted: prices can change under a shopper between
 * page loads. CartService surfaces that explicitly as a per-line notice rather
 * than letting it pass unnoticed. Price-at-add-time becomes meaningful at
 * *order* placement, where it is captured onto the order — an order is a
 * contract, a cart is an intention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('cart_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * Restricted rather than cascading.
             *
             * Products are soft-deleted, so the FK rarely fires — but if a
             * product is ever force-deleted, silently emptying live shoppers'
             * carts is worse than refusing the delete and making someone look
             * at why a saleable product is being destroyed.
             */
            $table->foreignId('product_id')
                ->constrained()
                ->restrictOnDelete();

            /*
             * The chosen variation, for variable products.
             *
             * Nullable because simple, digital, and customizable products have
             * no variants. When present it is the authoritative source of price
             * and stock for the line; the product is only the fallback.
             */
            $table->foreignId('product_variant_id')
                ->nullable()
                ->constrained()
                ->restrictOnDelete();

            $table->unsignedInteger('quantity');

            /*
             * Free-text personalisation for customizable products — an
             * engraving, a gift message. JSON so the shape can grow with the
             * product types that use it without a migration per field.
             */
            $table->json('options')->nullable();

            $table->timestamps();

            /*
             * One line per (cart, product, variant), enforced in the database.
             *
             * Not merely in the service: without the constraint, two concurrent
             * "add to cart" clicks both miss the existing row and both insert,
             * and the shopper sees the same item twice with the quantity split
             * between them. CartItem catches the violation and increments the
             * existing line instead.
             *
             * `variant_key` exists because a unique index cannot do this job
             * over `product_variant_id` directly: every SQL engine treats NULLs
             * as distinct in a unique index, so simple products — which have no
             * variant — would escape the constraint entirely. This column is the
             * same value with 0 standing in for null, and it is maintained by
             * the CartItem model's saving hook rather than by a generated
             * column, so the schema stays identical on MySQL and on the SQLite
             * the test suite runs against.
             */
            $table->unsignedBigInteger('variant_key')->default(0);

            $table->unique(['cart_id', 'product_id', 'variant_key'], 'cart_items_line_unique');

            $table->index('product_id', 'cart_items_product_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
