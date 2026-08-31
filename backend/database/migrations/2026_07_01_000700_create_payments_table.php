<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment attempts against an order.
 *
 * Many rows per order, not one. A customer whose card is declined twice before
 * succeeding produces three rows, and all three matter: the failures are what a
 * fraud review reads, and collapsing them into a single mutable
 * `orders.payment_reference` would erase exactly the evidence that a dispute
 * needs.
 *
 * `orders.payment_status` remains the summary — the question "is this order
 * paid?" must be answerable without aggregating this table on every list view —
 * and PaymentService is what keeps the two in step inside one transaction.
 *
 * ## No card data, ever
 *
 * There is no column here for a card number, a CVV, or an expiry date, and
 * there never should be. The gateway holds the instrument; this table holds its
 * *reference*. `card_last_four` and `card_brand` are display fragments returned
 * by the gateway for a receipt line, not the instrument itself — a stored PAN
 * would put this application in PCI scope and make a database backup a breach.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('order_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('method', 32);

            /** `pending`, `paid`, `failed` — the outcome of this attempt. */
            $table->string('status', 32);

            /*
             * Minor units, and the currency it was taken in. Both captured per
             * attempt rather than read from the order, so a partial payment or
             * a currency change between attempts is representable.
             */
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);

            /*
             * The gateway's identifier for this transaction — what a support
             * agent quotes when calling the processor.
             *
             * Indexed and unique-per-gateway rather than globally unique: two
             * gateways can legitimately issue the same reference string.
             */
            $table->string('gateway', 64)->nullable();
            $table->string('transaction_reference', 191)->nullable();

            /** Display fragments only. See the class docblock. */
            $table->string('card_brand', 32)->nullable();
            $table->string('card_last_four', 4)->nullable();

            /*
             * The gateway's raw response, for reconciliation and dispute
             * evidence. JSON because every processor's shape differs, and
             * modelling columns for one would break on the second.
             *
             * PaymentService strips known-sensitive keys before writing — a
             * gateway that echoes back the instrument must not have that echo
             * persisted here.
             */
            $table->json('gateway_response')->nullable();

            /** Why it failed, in a form a human can read. */
            $table->string('failure_reason', 512)->nullable();

            /*
             * Idempotency for the payment itself, distinct from the order's.
             * A retried capture must not take the money twice, and the unique
             * index is what guarantees that rather than a check-then-act.
             */
            $table->string('idempotency_key', 64)->nullable()->unique();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'created_at'], 'payments_order_index');
            $table->index(['status', 'created_at'], 'payments_status_index');
            $table->index(['gateway', 'transaction_reference'], 'payments_gateway_reference_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
