<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sellable variations of a variable product, and the attribute values that
 * define each one.
 *
 * A variant is the unit that actually carries stock and gets sold. "Medium /
 * Red" is one row in `product_variants` plus two rows in the pivot pointing at
 * the Medium and Red attribute values.
 *
 * The pivot is what keeps the combination dynamic. Storing "M/Red" as a string
 * on the variant would be simpler and immediately wrong: it could not answer
 * "which variants are Red" without a LIKE scan, and it would drift the moment
 * someone renamed the value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // A variant has no meaning without its parent, so this is the one
            // place a cascade is right.
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            // Unique across the whole catalog: a SKU is scanned at a warehouse
            // with no product context, so it must identify exactly one thing.
            $table->string('sku', 64)->unique();

            // Human-readable summary ("Medium / Red"), denormalised from the
            // pivot for display and order snapshots. Rebuilt on save.
            $table->string('name')->nullable();

            /*
             * Null means "inherit the product's price". This is not the same as
             * zero, and the distinction matters: a variable product usually
             * prices uniformly, and copying the parent price onto every variant
             * would mean a price change had to be applied N times, with the
             * variants silently diverging if one write failed.
             */
            $table->unsignedBigInteger('price')->nullable();
            $table->unsignedBigInteger('discount_price')->nullable();
            $table->unsignedBigInteger('cost_price')->nullable();

            // Authoritative stock for a variable product.
            $table->integer('stock')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(5);
            $table->boolean('allow_backorder')->default(false);

            $table->string('image')->nullable();

            $table->unsignedInteger('weight')->nullable();
            $table->unsignedInteger('length')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            $table->boolean('is_active')->default(true);

            // Exactly one variant per product may be the pre-selected option
            // on the product page. Enforced in the service, not the schema —
            // a partial unique index is not portable across MySQL and SQLite.
            $table->boolean('is_default')->default(false);

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['product_id', 'is_active', 'sort_order'], 'variants_product_index');
            $table->index(['product_id', 'stock'], 'variants_stock_index');
        });

        Schema::create('attribute_value_variant', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('product_variant_id')
                ->constrained('product_variants')
                ->cascadeOnDelete();

            // Restricted: deleting "Red" while variants are defined by it would
            // leave those variants unidentifiable. The value must be detached
            // from every variant first.
            $table->foreignId('attribute_value_id')
                ->constrained('attribute_values')
                ->restrictOnDelete();

            // A variant cannot be both Red and Blue — one value per attribute
            // is enforced in the service, since the attribute id lives one hop
            // away on attribute_values and cannot be constrained here.
            $table->unique(
                ['product_variant_id', 'attribute_value_id'],
                'variant_attribute_value_unique',
            );

            // Reverse lookup: "every variant that is Red", for faceted filters.
            $table->index('attribute_value_id', 'variant_by_attribute_value_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_value_variant');
        Schema::dropIfExists('product_variants');
    }
};
