<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shipping and billing addresses captured on an order.
 *
 * Two rows per order, not two sets of columns on `orders`. The column approach
 * needs `shipping_line1`, `billing_line1`, and so on for every field — fifteen
 * columns become thirty, and every piece of formatting and validation logic is
 * written twice and drifts once.
 *
 * Like the order's prices and product names, these are a **snapshot**. A
 * customer who later moves house must not retroactively change where a
 * delivered parcel was sent, and a courier dispute is unresolvable if the
 * address on the order is whatever the customer's profile says today.
 *
 * There is no foreign key to a saved address book for exactly that reason. The
 * address book (a later phase) is where a customer *chooses* from; this table
 * is what was *used*.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_addresses', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('order_id')
                ->constrained()
                ->cascadeOnDelete();

            /** `shipping` or `billing` — see App\Enums\AddressType. */
            $table->string('type', 16);

            /*
             * Recipient, which is not always the account holder: gifts, office
             * deliveries, and a spouse's name on the buzzer.
             */
            $table->string('first_name', 96);
            $table->string('last_name', 96);
            $table->string('company', 191)->nullable();

            /*
             * Contact for *this address*.
             *
             * A courier rings the number attached to the delivery, which may
             * not be the account's number when the parcel is going to someone
             * else. Nullable on billing, where it is rarely useful.
             */
            $table->string('phone', 32)->nullable();
            $table->string('email', 191)->nullable();

            /*
             * The address itself.
             *
             * Loose, unstructured, and permissive on purpose. Address formats
             * differ enormously between countries — a rigid schema modelled on
             * one country's postal system rejects valid addresses elsewhere,
             * which is a lost sale for a formatting opinion.
             */
            $table->string('line1', 255);
            $table->string('line2', 255)->nullable();
            $table->string('city', 128);
            $table->string('state', 128)->nullable();
            $table->string('postal_code', 32)->nullable();

            /** ISO 3166-1 alpha-2. */
            $table->string('country', 2);

            /** Delivery instructions — gate codes, "leave with the neighbour". */
            $table->string('delivery_instructions', 512)->nullable();

            $table->timestamps();

            /*
             * One address of each type per order, enforced in the database.
             *
             * Without it, a retried checkout step could attach a second
             * shipping address, and every read would have to decide which of
             * two rows is authoritative — a decision with no correct answer.
             */
            $table->unique(['order_id', 'type'], 'order_addresses_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_addresses');
    }
};
