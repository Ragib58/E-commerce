<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courier details and the resolved shipping zone on an order.
 *
 * `tracking_number` and `tracking_url` already existed. What was missing is
 * **which courier** — and the distinction matters more than it looks. A tracking
 * number without a carrier is unusable: the customer has a string and nowhere
 * to type it, and a support agent cannot tell Sundarban from Pathao from RedX
 * by looking at the digits.
 *
 * `courier_name` is stored as free text rather than a foreign key to a couriers
 * table. A store's courier list changes with commercial negotiations, an order
 * may be handed to a one-off carrier for a single remote delivery, and the
 * *name printed on the parcel* is a fact about that shipment rather than a
 * reference to a row that might later be renamed or deleted. Same snapshot
 * reasoning as `shipping_method_name`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            /*
             * Who is carrying the parcel. Snapshotted, per the class docblock.
             */
            $table->string('courier_name', 128)->nullable()->after('shipping_method_name');

            /*
             * The zone the delivery address resolved to at placement, and what
             * it was called at the time.
             *
             * Both stored. The id supports "how much do we ship to Chittagong"
             * reporting; the name survives the zone being renamed or deleted,
             * which is what keeps an old order's record readable. Neither is
             * used to price anything after placement — `shipping_total` is the
             * snapshot that governs.
             *
             * nullOnDelete rather than cascade: deleting a zone must not delete
             * orders shipped to it.
             */
            $table->foreignId('shipping_zone_id')
                ->nullable()
                ->after('courier_name')
                ->constrained('shipping_zones')
                ->nullOnDelete();

            $table->string('shipping_zone_name', 128)->nullable()->after('shipping_zone_id');

            /*
             * When the courier details were recorded.
             *
             * Distinct from `shipped_at`: an admin often has the tracking
             * number before the parcel is collected, and conflating the two
             * would either ship the order early or lose the number.
             */
            $table->timestamp('dispatched_at')->nullable()->after('shipped_at');
        });

        Schema::table('orders', function (Blueprint $table): void {
            // "Everything going out by this courier today" — the handover list a
            // warehouse prints when the van arrives.
            $table->index(['courier_name', 'shipped_at'], 'orders_courier_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_courier_index');
            $table->dropForeign(['shipping_zone_id']);
            $table->dropColumn([
                'courier_name',
                'shipping_zone_id',
                'shipping_zone_name',
                'dispatched_at',
            ]);
        });
    }
};
