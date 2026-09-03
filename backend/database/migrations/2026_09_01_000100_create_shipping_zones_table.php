<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geographic zones a shipping method can charge differently for.
 *
 * ## Why zones are a table and not columns on shipping_methods
 *
 * The brief's example — "Inside Dhaka" and "Outside Dhaka" — looks like it could
 * be two boolean columns or two rows in `shipping_methods`. Both are wrong for
 * the same reason: the store that needs those two today needs "Inside Dhaka /
 * Outside Dhaka / Chittagong / Rest of Bangladesh" next quarter, and a column
 * per region is a migration per region.
 *
 * A zone is a *named set of places*. A method's price *within* a zone lives in
 * `shipping_rates`, which is the join between the two. That separation is what
 * lets "Express" cost 100 inside Dhaka and 250 outside it without duplicating
 * the method, and what lets a new zone be an INSERT rather than a deploy.
 *
 * ## Matching is by explicit lists, not geometry
 *
 * A zone holds arrays of countries, states, cities, and postcodes. No polygons,
 * no radius, no geocoding — those need a spatial database and an address
 * quality this application does not have. A Bangladeshi store's real question is
 * "is this address in Dhaka?", and the honest answer comes from a list of names
 * the operator maintains, not from a lat/long the customer never supplied.
 *
 * ## Priority resolves overlap
 *
 * Zones can and will overlap: "Dhaka" is inside "Bangladesh". Rather than
 * forbidding that — which would make a nationwide fallback impossible — the
 * most *specific* match wins, decided by `priority`. A catch-all zone sits at
 * the bottom and is what stops an unmatched address silently having no shipping
 * option at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_zones', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('name', 128);

            /*
             * A stable slug the operator chooses. Unlike the uuid it is
             * readable, so a rate import or an export names `inside-dhaka`
             * rather than a random string.
             */
            $table->string('code', 64)->unique();

            $table->string('description', 512)->nullable();

            /*
             * The places this zone covers.
             *
             * All four are JSON arrays and all four are optional. A zone with
             * only `countries` is a whole-country zone; one with `cities` is a
             * metro zone. Matching is case-insensitive and trimmed at read
             * time — an operator typing "dhaka" and a customer typing "Dhaka"
             * must not produce two different shipping prices.
             *
             * Postcodes support a trailing wildcard (`120*`), because postal
             * systems are hierarchical and listing every code in a district by
             * hand is how a zone ends up subtly incomplete.
             */
            $table->json('countries')->nullable();
            $table->json('states')->nullable();
            $table->json('cities')->nullable();
            $table->json('postcodes')->nullable();

            /*
             * Higher wins when several zones match.
             *
             * "Inside Dhaka" must beat "Bangladesh" for a Dhaka address, and
             * the only way to express that without inspecting the shape of each
             * zone is to let the operator say which is more specific.
             */
            $table->unsignedInteger('priority')->default(0);

            /*
             * Whether this zone matches anything not matched by another.
             *
             * At most one should be set, and ShippingZoneService reads the
             * highest-priority one. It exists so an address in an unlisted
             * country gets *a* price rather than silently having no shipping
             * option — which at checkout reads as a broken store rather than
             * as "we do not ship there".
             */
            $table->boolean('is_fallback')->default(false);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // The resolution query: active zones, most specific first.
            $table->index(['is_active', 'priority'], 'shipping_zones_resolution_index');
            $table->index('is_fallback', 'shipping_zones_fallback_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_zones');
    }
};
