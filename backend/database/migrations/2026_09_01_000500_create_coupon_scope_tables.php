<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a coupon is restricted to: products, categories, or named customers.
 *
 * Three pivot tables rather than three JSON columns on `coupons`. JSON would be
 * simpler to write and impossible to query in the direction an admin actually
 * asks: "which coupons apply to this product" is a routine question when
 * someone is about to change a price, and answering it from a JSON array means
 * scanning every coupon row.
 *
 * The tables are also what let the database enforce that a scope references a
 * real record — a JSON array of ids happily survives the product being deleted,
 * and the coupon then silently applies to nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Product scope.
         *
         * cascadeOnDelete on both sides: a scope row without its coupon or its
         * product means nothing. Deleting a product narrows the coupon rather
         * than breaking it — and if that empties the scope entirely,
         * CouponService treats the coupon as matching no lines, which is the
         * safe reading.
         */
        Schema::create('coupon_product', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            /*
             * Whether this row includes or excludes.
             *
             * An exclusion list is frequently what an operator actually wants —
             * "20% off everything except gift cards" is one coupon with one
             * exclusion, whereas expressing it as an inclusion list means
             * listing the entire catalog and remembering to update it whenever
             * a product is added.
             */
            $table->boolean('is_excluded')->default(false);

            $table->unique(['coupon_id', 'product_id'], 'coupon_product_unique');
            $table->index('product_id', 'coupon_product_product_index');
        });

        Schema::create('category_coupon', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();

            $table->boolean('is_excluded')->default(false);

            /*
             * Whether the restriction reaches child categories.
             *
             * Categories nest to unlimited depth in this project, so "20% off
             * Electronics" almost always means the whole subtree. Defaulting to
             * true matches the intent; an operator who means one level says so.
             */
            $table->boolean('includes_descendants')->default(true);

            $table->unique(['coupon_id', 'category_id'], 'category_coupon_unique');
            $table->index('category_id', 'category_coupon_category_index');
        });

        /*
         * Customer scope — a coupon issued to named people.
         *
         * Distinct from `per_user_limit`, which bounds how often *anyone* may
         * use a public coupon. This restricts *who* may use it at all: a
         * make-good sent to one customer after a bad delivery must not be
         * usable by whoever they forward the email to.
         */
        Schema::create('coupon_user', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->unique(['coupon_id', 'user_id'], 'coupon_user_unique');
            $table->index('user_id', 'coupon_user_user_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_user');
        Schema::dropIfExists('category_coupon');
        Schema::dropIfExists('coupon_product');
    }
};
