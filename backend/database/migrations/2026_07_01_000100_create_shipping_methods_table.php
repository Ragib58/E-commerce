<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery services the store offers, and what each costs.
 *
 * A table rather than an enum, because unlike payment methods a shipping option
 * needs no integration code — it is a name, a price, and a delivery estimate.
 * Adding "Same-day, Dhaka only, ৳250" is an INSERT, which is the whole point of
 * this project's "fully dynamic" rule.
 *
 * `rate` is the authoritative price and is read from this row at checkout, never
 * accepted from the client — the same rule the catalog follows. A checkout
 * session stores the shipping method's *id*; the cost is looked up.
 *
 * ## Free-shipping threshold
 *
 * `free_above` is per method rather than a single store setting, because the
 * threshold that makes sense for standard post rarely makes sense for express.
 * Null means the method is never free.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_methods', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('name', 128);
            $table->string('code', 64)->unique();
            $table->string('description', 512)->nullable();

            /*
             * Minor units, matching every other money column in the schema.
             * Storing currency as an integer count of the smallest unit is what
             * keeps totals exact — a float shipping rate would reintroduce the
             * rounding error the rest of the system avoids.
             */
            $table->unsignedInteger('rate')->default(0);

            /*
             * Order subtotal at or above which this method costs nothing.
             * Null = never free.
             */
            $table->unsignedInteger('free_above')->nullable();

            /*
             * The delivery estimate shown at checkout. Two integers rather than
             * a free-text "3-5 days" so the estimate can be turned into actual
             * dates, sorted, and compared between methods.
             */
            $table->unsignedSmallInteger('min_days')->nullable();
            $table->unsignedSmallInteger('max_days')->nullable();

            /*
             * Availability constraints. Null means unconstrained.
             *
             * `countries` is a JSON list of ISO codes; a method absent from a
             * shopper's country is not offered at all rather than offered and
             * then rejected.
             */
            $table->json('countries')->nullable();

            // Weight and value bounds, for carriers that refuse parcels outside
            // a range. Null on either side leaves that side open.
            $table->unsignedInteger('min_subtotal')->nullable();
            $table->unsignedInteger('max_subtotal')->nullable();

            $table->boolean('is_active')->default(true);

            /*
             * Whether choosing this method requires a shipping address at all.
             *
             * False for digital-only fulfilment: an order of downloads has
             * nowhere to ship, and demanding a postcode for it is the kind of
             * friction that reads as a broken form.
             */
            $table->boolean('requires_address')->default(true);

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            // The storefront's query: active methods in display order.
            $table->index(['is_active', 'sort_order'], 'shipping_methods_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_methods');
    }
};
