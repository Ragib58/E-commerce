<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every inbound callback and webhook, recorded before it is acted on.
 *
 * ## Two jobs, both load-bearing
 *
 * **Replay protection.** The unique index on `(gateway, event_id)` is what
 * makes duplicate delivery harmless. Gateways retry aggressively — Stripe will
 * redeliver an event for days until it gets a 2xx — and a customer refreshing
 * the return page produces the same callback again. Without this, each delivery
 * would run the settlement path afresh: repeated history rows, repeated
 * notifications, and with a naive refund implementation, repeated payouts.
 *
 * A check-then-act in PHP cannot close this. Two retries arriving concurrently
 * would both find no prior row and both proceed. The unique index is what makes
 * the second one fail, and PaymentService treats that failure as "already
 * handled" rather than an error.
 *
 * **Forensics.** Payment disputes are settled by evidence, and "what exactly
 * did the gateway tell us, and when" is the question. Rows are kept even when
 * the event was rejected — an invalid signature is precisely the thing worth
 * having a record of.
 *
 * ## Why callbacks are stored here too
 *
 * A browser redirect is not a webhook. It is recorded in the same table anyway
 * because it needs the same replay protection — a refreshed success page is a
 * duplicate delivery by another name — and because a support agent
 * reconstructing an incident wants one chronological list, not two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->id();

            $table->string('gateway', 64);

            /** `webhook` or `callback` — how the notification reached us. */
            $table->string('source', 16)->default('webhook');

            /*
             * The gateway's own id for this event.
             *
             * Nullable because not every processor issues one; for those, the
             * dedupe key is synthesised from the transaction reference and
             * event type — see PaymentService::recordWebhookEvent.
             */
            $table->string('event_id', 191)->nullable();

            /** The normalised type, e.g. `payment.succeeded`. */
            $table->string('event_type', 64)->nullable();

            /** The gateway's transaction reference, used to find the payment. */
            $table->string('transaction_reference', 191)->nullable();

            /*
             * The payment this resolved to.
             *
             * Nullable and nullOnDelete: an event may arrive for a payment that
             * does not exist here — a mis-routed webhook, or a test event fired
             * from a gateway's dashboard — and that is worth recording rather
             * than discarding.
             */
            $table->foreignId('payment_id')
                ->nullable()
                ->constrained('payments')
                ->nullOnDelete();

            $table->foreignId('order_id')
                ->nullable()
                ->constrained('orders')
                ->nullOnDelete();

            /*
             * Whether the signature verified.
             *
             * A false here is a security event: someone posted to the webhook
             * endpoint with a bad signature. Stored rather than only logged so
             * it is queryable — one is noise, a hundred is an attack.
             */
            $table->boolean('is_verified')->default(false);

            /** Whether the event actually changed anything. */
            $table->boolean('is_processed')->default(false);

            /** Why it was ignored or rejected, when it was. */
            $table->string('rejection_reason', 512)->nullable();

            /*
             * The payload as received, credentials stripped.
             *
             * The evidence a dispute turns on. Sanitised by the gateway before
             * it reaches here — processors echo back more than they should, and
             * this table is readable by any admin holding `view_payments`.
             */
            $table->json('payload')->nullable();

            $table->string('ip_address', 45)->nullable();

            $table->timestamp('processed_at')->nullable();

            // No updated_at: a received event is a fact about a moment and is
            // never revised. Offering the column would invite code that tries.
            $table->timestamp('created_at')->nullable();

            /*
             * The replay guard. See the class docblock — this index, not any
             * application check, is what makes duplicate delivery safe.
             */
            $table->unique(['gateway', 'event_id'], 'payment_webhook_events_unique');

            // Reconstructing one payment's history.
            $table->index(['payment_id', 'created_at'], 'payment_webhook_events_payment_index');

            // Finding events for a reference that never resolved to a payment.
            $table->index(['gateway', 'transaction_reference'], 'payment_webhook_events_reference_index');

            // The security query: unverified attempts over a period.
            $table->index(['is_verified', 'created_at'], 'payment_webhook_events_verified_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
