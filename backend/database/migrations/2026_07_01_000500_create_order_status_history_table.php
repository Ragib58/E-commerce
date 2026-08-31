<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An append-only record of every status change an order has been through.
 *
 * The same discipline as the inventory ledger, for the same reason: `orders.status`
 * holds the current state and nothing about how it got there. Without this table
 * "who cancelled this and when?" is unanswerable, and that question is asked
 * every time a customer disputes something.
 *
 * Nothing here is ever updated or deleted. Both sides of each change are stored
 * (`from_status`, `to_status`) so a row is independently meaningful without
 * replaying the whole timeline, and so a gap — one row's `to_status` not
 * matching the next row's `from_status` — is detectable rather than silent.
 *
 * Payment status changes are recorded here too, in the same stream. A refund is
 * one event that moves both the order and the money, and splitting it across two
 * tables would make the timeline something a reader has to reassemble by
 * interleaving timestamps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_history', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('order_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * Which stream this row belongs to: `order` or `payment`.
             *
             * One table, two streams, because they are read together as a
             * single chronological timeline.
             */
            $table->string('stream', 16)->default('order');

            /** Null on the first row: the order came from nowhere. */
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);

            /*
             * Who did it. Exactly one of these is set, or neither for an
             * automated transition (a scheduled auto-cancel, a gateway
             * callback).
             *
             * nullOnDelete on both: deleting a staff account or a customer must
             * never erase the record of what they did. The audit trail outlives
             * the actor, exactly as the inventory ledger does.
             */
            $table->foreignId('admin_id')
                ->nullable()
                ->constrained('admins')
                ->nullOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
             * A label for the actor, captured at write time.
             *
             * Denormalised on purpose: it survives the account being deleted,
             * which is precisely when a timeline is most likely to be
             * scrutinised. Also carries `system` for automated changes, which
             * no foreign key can represent.
             */
            $table->string('actor_label', 128)->nullable();

            /** Why. Free text — "customer called", "out of stock". */
            $table->string('comment', 512)->nullable();

            /*
             * Whether the customer was told about this change. Recorded so a
             * support agent can see whether the shopper already knows, rather
             * than guessing from whether a notification job happened to run.
             */
            $table->boolean('notified_customer')->default(false);

            // No updated_at: rows are immutable, and a column implying
            // otherwise invites code that tries to use it.
            $table->timestamp('created_at')->nullable();

            // The order's timeline, oldest first — the only query this table
            // serves in the panel.
            $table->index(['order_id', 'created_at'], 'order_status_history_order_index');

            // Reporting: how many orders entered a given state in a period.
            $table->index(['to_status', 'created_at'], 'order_status_history_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
    }
};
