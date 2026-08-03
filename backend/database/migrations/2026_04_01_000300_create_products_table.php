<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catalog's central table.
 *
 * Two decisions here shape everything downstream:
 *
 * Money is stored as an integer count of minor units (cents), never as a float
 * or a decimal string. Floats cannot represent 0.10 exactly, so summing a cart
 * of them drifts; storing minor units makes every arithmetic operation exact
 * and every comparison total. The application layer converts at the boundary.
 *
 * `stock` on a *variable* product is a cached roll-up of its variants, not an
 * authoritative figure. Inventory writes always target the row that owns the
 * stock — the variant — and the roll-up is recomputed from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();

            // Public-facing opaque identifier. Sequential ids leak catalog size
            // and invite enumeration of unpublished records.
            $table->uuid('uuid')->unique();

            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sku', 64)->unique();

            $table->string('short_description', 512)->nullable();
            $table->longText('description')->nullable();

            /*
             * Both nullable and both nulled-on-delete rather than cascading: a
             * product outliving its brand is a data-quality problem to fix in
             * the panel, whereas cascading would delete saleable inventory —
             * and its order history's product reference — because someone
             * tidied up a brand list.
             */
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();

            $table->foreignId('brand_id')
                ->nullable()
                ->constrained('brands')
                ->nullOnDelete();

            $table->string('type', 24)->default('simple');

            // Minor units (cents). See the class docblock.
            $table->unsignedBigInteger('price')->default(0);
            $table->unsignedBigInteger('discount_price')->nullable();

            // Never exposed by the public API — margin is not public data.
            $table->unsignedBigInteger('cost_price')->nullable();

            // Per-product override. Null means "use the store's default rate",
            // which is a settings value, so a VAT change is one edit not a
            // catalog-wide UPDATE.
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->boolean('is_taxable')->default(true);

            $table->integer('stock')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(5);

            // Lets a product keep selling past zero (back-orders) without
            // needing a fake stock figure to represent it.
            $table->boolean('allow_backorder')->default(false);

            // Grams and millimetres — integers, for the same reason as money.
            $table->unsignedInteger('weight')->nullable();
            $table->unsignedInteger('length')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            $table->string('status', 16)->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_new_arrival')->default(false);
            $table->boolean('is_best_seller')->default(false);

            $table->string('meta_title')->nullable();
            $table->string('meta_description', 512)->nullable();
            $table->string('og_image')->nullable();

            $table->string('video_url', 2048)->nullable();

            // Set when a draft first becomes published. Distinct from
            // created_at, which is when the row was drafted — "new arrivals"
            // means recently *on sale*, not recently typed in.
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            /*
             * Index strategy follows the storefront's actual access paths.
             *
             * Every public listing filters on status first, so it leads each
             * composite. Column order matters: a (status, X) index serves a
             * status-only query too, whereas (X, status) does not.
             */
            $table->index(['status', 'published_at'], 'products_published_index');
            $table->index(['category_id', 'status', 'price'], 'products_category_browse_index');
            $table->index(['brand_id', 'status'], 'products_brand_index');
            $table->index(['status', 'price'], 'products_price_sort_index');

            // Merchandising rails on the homepage. Partial-index semantics are
            // not portable, so these are plain composites.
            $table->index(['status', 'is_featured'], 'products_featured_index');
            $table->index(['status', 'is_new_arrival'], 'products_new_arrival_index');
            $table->index(['status', 'is_best_seller'], 'products_best_seller_index');

            // Drives the low-stock report: comparing two columns cannot use an
            // index, so this narrows the scan to tracked, live products first.
            $table->index(['status', 'stock'], 'products_stock_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
