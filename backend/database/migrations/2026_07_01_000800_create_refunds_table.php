<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money returned to a customer.
 *
 * Its own table rather than a negative `payments` row. A refund carries fields a
 * payment never has — who authorised it, why, whether stock came back, which
 * lines it covered — and overloading one table would leave every column
 * nullable and every query filtered by sign.
 *
 * Many rows per order, because partial refunds compose: one line refunded this
 * week, the shipping waived next. `orders.refunded_total` is the running sum,
 * maintained by RefundService inside the same transaction as the row, and it is
 * what a further refund is checked against so the store cannot return more than
 * it took.
 *
 * `is_restocked` records whether the goods came back to the shelf, per refund.
 * Refunding a damaged item the store does not want returned is ordinary, and
 * silently restocking it would put a broken product back on sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('order_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * The payment being reversed, where one is identifiable.
             *
             * Nullable: an offline order refunded by bank transfer has no
             * original payment row to point at, and a goodwill credit may not
             * correspond to any single attempt.
             */
            $table->foreignId('payment_id')
                ->nullable()
                ->constrained('payments')
                ->nullOnDelete();

            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);

            /** `pending`, `completed`, `failed`. */
            $table->string('status', 32)->default('pending');

            /*
             * Who authorised it. Refunds are staff actions — nullOnDelete keeps
             * the record after the account goes, which is when it matters most.
             */
            $table->foreignId('admin_id')
                ->nullable()
                ->constrained('admins')
                ->nullOnDelete();

            $table->string('actor_label', 128)->nullable();

            /** Why. Required by the service even though the column is nullable. */
            $table->string('reason', 512)->nullable();

            /*
             * Which lines and quantities this refund covered, as
             * `[{"order_item_id": 1, "quantity": 2, "amount": 4000}]`.
             *
             * JSON rather than a pivot table: a refund's line breakdown is
             * written once and read as a whole, never queried across orders.
             * Null for an order-level refund that is not attributed to lines —
             * a waived shipping fee.
             */
            $table->json('line_items')->nullable();

            /** Whether the goods returned to sellable stock. */
            $table->boolean('is_restocked')->default(false);

            $table->string('gateway', 64)->nullable();
            $table->string('transaction_reference', 191)->nullable();
            $table->json('gateway_response')->nullable();
            $table->string('failure_reason', 512)->nullable();

            /** Stops a double-clicked refund button paying out twice. */
            $table->string('idempotency_key', 64)->nullable()->unique();

            $table->timestamp('refunded_at')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'created_at'], 'refunds_order_index');
            $table->index(['status', 'created_at'], 'refunds_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
