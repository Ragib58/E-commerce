<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gateway lifecycle columns on `payments`.
 *
 * The table already held the transaction id, gateway, amount, currency, status,
 * response, and paid-at that the brief requires. What it lacked was the shape
 * of a *remote* payment's life: a payment that has been initiated but not
 * settled, one the customer cancelled, and the record of when a status was last
 * established by an actual server-side check.
 *
 * Added as a separate migration rather than by editing the original, because
 * the original has run in every environment this project has been deployed to.
 * Rewriting a migration that has already run is how two databases end up
 * claiming the same schema version with different columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            /*
             * When the customer was sent to the gateway.
             *
             * The presence of this with no `paid_at` and no `failed_at` is what
             * identifies an abandoned payment — the customer went to pay and
             * never came back. Those are the rows a reconciliation sweep polls,
             * and without a timestamp there is no way to tell a payment started
             * thirty seconds ago from one started last week.
             */
            $table->timestamp('initiated_at')->nullable()->after('status');

            /*
             * When a *server-side verification* last ran.
             *
             * Deliberately not "when we last received a callback". A callback
             * is an untrusted browser navigation; this column records the last
             * time the application actually asked the gateway and got an
             * answer, which is the only evidence that means anything in a
             * dispute.
             */
            $table->timestamp('verified_at')->nullable()->after('paid_at');

            /** When the customer abandoned the payment at the gateway. */
            $table->timestamp('cancelled_at')->nullable()->after('failed_at');

            /*
             * How many times settlement has been attempted.
             *
             * Duplicate callbacks and webhook retries are ordinary, not
             * exceptional — a gateway may deliver the same event a dozen times.
             * A count makes an unusual number visible without inferring it from
             * log volume.
             */
            $table->unsignedInteger('attempt_count')->default(0)->after('cancelled_at');

            /*
             * The URL the customer was sent to.
             *
             * Kept for support: "I clicked pay and nothing happened" is
             * answerable if the exact redirect is on record, and unanswerable
             * otherwise. Not a secret — the customer's own browser had it.
             */
            $table->string('redirect_url', 1024)->nullable()->after('attempt_count');
        });

        Schema::table('payments', function (Blueprint $table): void {
            /*
             * Finding a payment from a gateway's reference.
             *
             * Every callback and webhook arrives carrying the processor's id
             * and nothing else useful, so this is the lookup on the hottest
             * path in the whole payment flow. Composite with `gateway` because
             * two processors can legitimately issue the same reference string,
             * and matching on the reference alone could return another
             * gateway's payment.
             */
            $table->index(['gateway', 'transaction_reference'], 'payments_gateway_lookup_index');

            // The reconciliation sweep: initiated, never settled.
            $table->index(['status', 'initiated_at'], 'payments_pending_sweep_index');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('payments_gateway_lookup_index');
            $table->dropIndex('payments_pending_sweep_index');

            $table->dropColumn([
                'initiated_at',
                'verified_at',
                'cancelled_at',
                'attempt_count',
                'redirect_url',
            ]);
        });
    }
};
