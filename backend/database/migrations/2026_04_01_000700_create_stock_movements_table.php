<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The inventory ledger: an append-only record of every stock change.
 *
 * Nothing in this table is ever updated or deleted. A mistake is corrected by
 * posting an opposing movement, exactly as in double-entry bookkeeping. That is
 * what makes the history trustworthy — if a row could be edited, "we had 40 on
 * the 3rd" would be an assertion rather than a fact, and stock disputes with
 * suppliers or auditors would be unresolvable.
 *
 * Each row stores the levels on *both* sides of the change (`quantity_before`,
 * `quantity_after`). That is deliberate redundancy: it makes each row
 * independently meaningful without replaying the whole ledger, and it turns a
 * lost or out-of-order write into something detectable — a gap where one row's
 * `quantity_after` does not match the next row's `quantity_before`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            // Null for a simple product, set when the movement targets a
            // specific variant. Exactly one of these two identifies the stock
            // that moved.
            $table->foreignId('product_variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->cascadeOnDelete();

            $table->string('type', 16);
            $table->string('reason', 32);

            // Signed: the actual delta applied, already carrying the type's
            // direction. Summing this column over a period reconstructs the
            // net change without re-deriving each row's sign.
            $table->integer('quantity');

            $table->integer('quantity_before');
            $table->integer('quantity_after');

            /*
             * Who did it. Null for system-generated movements (an order
             * pipeline decrement has no admin behind it).
             *
             * nullOnDelete rather than cascade: deleting a staff account must
             * never erase the inventory history they recorded. The ledger
             * outlives the person.
             */
            $table->foreignId('admin_id')
                ->nullable()
                ->constrained('admins')
                ->nullOnDelete();

            // Free-text note — a supplier invoice number, a stock take
            // reference, an explanation for a write-off.
            $table->string('note', 512)->nullable();

            /*
             * Polymorphic link to whatever caused the movement (an order, a
             * return, a purchase order). Nullable because manual adjustments
             * have no originating document. Left unconstrained by design: it
             * points at tables that later phases introduce.
             */
            $table->nullableMorphs('reference');

            // No updated_at: rows are immutable, and offering a column that
            // implies otherwise invites code that tries to use it.
            $table->timestamp('created_at')->nullable();

            // The product's movement history, newest first — the panel's
            // default view.
            $table->index(['product_id', 'created_at'], 'stock_movements_product_index');
            $table->index(['product_variant_id', 'created_at'], 'stock_movements_variant_index');

            // Reporting: shrinkage by reason over a date range.
            $table->index(['reason', 'created_at'], 'stock_movements_reason_index');
            $table->index(['type', 'created_at'], 'stock_movements_type_index');
            $table->index('admin_id', 'stock_movements_admin_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
