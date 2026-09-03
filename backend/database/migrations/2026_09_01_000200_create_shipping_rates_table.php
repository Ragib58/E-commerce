<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a shipping method costs within a particular zone.
 *
 * The join that makes zoned pricing work: "Express" is one method, but it costs
 * 100 inside Dhaka and 250 outside it. Without this table the store would need
 * two methods called "Express (Dhaka)" and "Express (outside Dhaka)", both shown
 * to every shopper, one of which is always wrong for them.
 *
 * ## The fallback chain
 *
 * A method does not need a rate row for every zone. Resolution is:
 *
 *   1. A rate for this method in the matched zone.
 *   2. Failing that, the method's own `rate` / `free_above` columns.
 *
 * So an operator configures the exceptions and leaves the default alone. A
 * method with no rate rows behaves exactly as it did before zones existed,
 * which is what keeps this migration from changing the behaviour of an existing
 * store on deploy.
 *
 * ## Free-shipping threshold lives here too
 *
 * `free_above` is per (method, zone) rather than per method, because the
 * threshold that makes sense for a local courier rarely makes sense for a
 * national one — free over 1000 taka inside Dhaka may be sustainable where free
 * over 1000 nationwide is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_rates', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('shipping_method_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * cascadeOnDelete: a rate without its zone prices nothing, and
             * leaving the row would make it unreachable rather than harmless.
             *
             * The method side cascades for the same reason. Neither cascade
             * touches an *order* — orders snapshot their shipping cost and
             * method name at placement, so deleting a rate cannot rewrite what
             * a customer was charged.
             */
            $table->foreignId('shipping_zone_id')
                ->constrained()
                ->cascadeOnDelete();

            /** Minor units, matching every other money column in the schema. */
            $table->unsignedInteger('rate');

            /*
             * Order subtotal at or above which this method is free in this
             * zone. Null means never free — deliberately distinct from 0, which
             * would mean "free from the first penny".
             */
            $table->unsignedInteger('free_above')->nullable();

            /*
             * Subtotal bounds for this rate.
             *
             * Lets an operator express weight-band-style pricing without a
             * weight table: "under 2000 costs 60, over it costs 100" is two
             * rows. Null on either side leaves that side open.
             */
            $table->unsignedInteger('min_subtotal')->nullable();
            $table->unsignedInteger('max_subtotal')->nullable();

            /*
             * Delivery estimate for this zone specifically. Null falls back to
             * the method's own estimate — a courier is slower to a remote zone,
             * and quoting the metro figure everywhere is how a store gets
             * complaints about late delivery it never promised.
             */
            $table->unsignedSmallInteger('min_days')->nullable();
            $table->unsignedSmallInteger('max_days')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            /*
             * One rate per (method, zone, subtotal band).
             *
             * The band is part of the key so the weight-band case above remains
             * expressible; without `min_subtotal` in the unique index, the two
             * rows for one method and zone would collide.
             *
             * `min_subtotal` is coalesced to 0 by the model's saving hook —
             * every SQL engine treats NULLs as distinct in a unique index,
             * which would exempt the unbounded row from the constraint
             * entirely. Same reasoning as `cart_items.variant_key`.
             */
            $table->unsignedInteger('min_subtotal_key')->default(0);

            $table->unique(
                ['shipping_method_id', 'shipping_zone_id', 'min_subtotal_key'],
                'shipping_rates_unique',
            );

            // The lookup: rates for a zone, cheapest first.
            $table->index(['shipping_zone_id', 'is_active'], 'shipping_rates_zone_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rates');
    }
};
