<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Threaded notes against an order.
 *
 * Separate from `orders.admin_note` and `orders.customer_note`, which hold the
 * single note captured at placement. This table is the running conversation:
 * many notes, each with an author and a time, added as an order progresses.
 *
 * ## The visibility flag is the whole point
 *
 * `is_customer_visible` decides whether a note appears on the customer's order
 * page. Getting this wrong in the exposing direction is the failure that
 * matters — an internal note reading "customer is being difficult, hold the
 * refund" rendered on their order page is a serious incident.
 *
 * So the column defaults to **false**. A note is internal unless someone
 * explicitly decides otherwise, and the resource that serialises notes filters
 * on this column rather than trusting the caller to pass the right scope. A
 * default of true, or a filter applied only in the controller, would make
 * "forgot to set it" and "forgot to filter it" both resolve to disclosure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_notes', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('order_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * The author. Exactly one is set — staff or customer — or neither
             * for a system-generated note.
             *
             * nullOnDelete: the note outlives the account, like every other
             * audit record here.
             */
            $table->foreignId('admin_id')
                ->nullable()
                ->constrained('admins')
                ->nullOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /** Author label captured at write time, surviving account deletion. */
            $table->string('author_label', 128)->nullable();

            $table->text('body');

            /*
             * False by default. See the class docblock — this default is a
             * safety property, not a convenience.
             */
            $table->boolean('is_customer_visible')->default(false);

            /*
             * Whether the customer was emailed this note. Only meaningful when
             * the note is visible to them.
             */
            $table->boolean('notified_customer')->default(false);

            $table->timestamps();

            $table->index(['order_id', 'created_at'], 'order_notes_order_index');

            // The customer's view of the thread: visible notes on one order.
            $table->index(['order_id', 'is_customer_visible'], 'order_notes_visible_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notes');
    }
};
