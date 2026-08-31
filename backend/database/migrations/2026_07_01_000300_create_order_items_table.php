<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lines on an order.
 *
 * ## Everything a line needs to be readable forever
 *
 * The product name, sku, variant name, and attribute selections are all copied
 * onto the row rather than read through the relation. That looks like
 * denormalisation and is: an invoice must render identically in five years, and
 * by then the product may have been renamed, restructured into different
 * variants, or archived. A join to the live catalog would silently rewrite
 * history — the customer's receipt would stop matching the box that arrived.
 *
 * The foreign keys are kept anyway, so "show me everything ordered of this
 * product" is still one query. They are for *analysis*; the copied columns are
 * for *the record*.
 *
 * ## Prices here are the server's snapshot
 *
 * `unit_price` is what the catalog said at placement, captured by OrderService
 * inside the placing transaction. No API request carries a field that reaches
 * this column — the same guarantee the cart makes by having no price column at
 * all, achieved here by there being no write path from a request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('order_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * The catalog rows, for reporting.
             *
             * nullOnDelete, unlike the cart's restrictOnDelete. A cart line
             * without its product is meaningless and should block the delete;
             * an order line without it is still a complete historical record,
             * because every displayable field is copied below. Blocking a
             * product deletion forever because one order mentions it would make
             * the catalog un-prunable.
             */
            $table->foreignId('product_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('product_variant_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            /*
             * The snapshot. These are what the invoice prints.
             */
            $table->string('product_name', 255);
            $table->string('product_sku', 128)->nullable();
            $table->string('variant_name', 255)->nullable();
            $table->string('product_type', 32)->nullable();

            /*
             * The chosen attribute values, as `{"Size": "M", "Colour": "Navy"}`.
             *
             * Resolved to labels at placement rather than stored as attribute
             * ids: an admin renaming the "Colour" attribute or deleting a value
             * must not change what an existing packing slip tells the picker to
             * put in the box.
             */
            $table->json('variant_options')->nullable();

            /** Personalisation carried over from the cart line. */
            $table->json('options')->nullable();

            /** Image at placement, so an order history page renders without the catalog. */
            $table->string('thumbnail_url', 512)->nullable();

            $table->unsignedInteger('quantity');

            /*
             * Money, minor units.
             *
             * `unit_price` is the price actually charged per unit;
             * `list_price` is what it would have cost without the discount,
             * stored so an invoice can show "was £25, now £20" and so margin
             * reporting does not have to reconstruct it.
             */
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('list_price')->nullable();

            /** Per-line discount, in minor units, for the whole line. */
            $table->unsignedBigInteger('discount_total')->default(0);

            /** Tax attributed to this line. Summing the column gives the order's tax. */
            $table->unsignedBigInteger('tax_total')->default(0);

            /*
             * quantity × unit_price, before tax and after discount.
             *
             * Stored rather than computed on read so that a line total can be
             * asserted against its components — a mismatch is then a detectable
             * corruption rather than an invisible one.
             */
            $table->unsignedBigInteger('line_total');

            $table->boolean('is_taxable')->default(true);

            /*
             * Whether this line's stock was decremented.
             *
             * Digital products and backordered lines do not hold stock, so a
             * cancellation must not return units for them. Recorded per line at
             * placement rather than re-derived later from the product's current
             * type, which can change.
             */
            $table->boolean('stock_was_reduced')->default(false);

            /** How many units of this line have been sent back. */
            $table->unsignedInteger('refunded_quantity')->default(0);

            $table->timestamps();

            $table->index('order_id', 'order_items_order_index');

            // "Everything ever ordered of this product" — the sales report.
            $table->index(['product_id', 'created_at'], 'order_items_product_index');
            $table->index('product_variant_id', 'order_items_variant_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
