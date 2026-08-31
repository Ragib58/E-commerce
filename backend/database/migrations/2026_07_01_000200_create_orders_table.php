<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders.
 *
 * ## An order stores money; a cart does not
 *
 * `cart_items` deliberately has no price column — see its migration. This table
 * is the opposite, and for the same underlying reason. A cart is an *intention*,
 * so its prices must track the catalog. An order is a *contract*: it records
 * what both sides agreed at a moment in time, and it must not change when the
 * catalog does. If a product's price rises tomorrow, last week's invoice still
 * says what the customer actually paid.
 *
 * The distinction is what makes accepting a client-supplied price impossible
 * anyway: these figures are computed by OrderService from the catalog inside the
 * placing transaction, and no request field maps to any column here. The order
 * is a *snapshot the server took*, not a document the client submitted.
 *
 * ## Duplicate prevention
 *
 * `idempotency_key` is uniquely indexed. A double-clicked "Place order", a
 * retried request after a timeout, or a client replaying a payload all present
 * the same key, and the second INSERT fails at the database rather than at a
 * check that a concurrent request could interleave past. Application-level
 * "does an order already exist?" logic cannot close that window; a unique index
 * can.
 *
 * ## Customer identity
 *
 * `user_id` is nullable because guest checkout is a first-class path, not a
 * degraded one. The contact details are therefore *also* stored on the order
 * rather than read through the relation — a guest has no account row to read
 * from, and a registered customer who later changes their email must not
 * retroactively alter which address an old order was confirmed to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();

            /*
             * The public identifier. Every customer-facing URL and API response
             * uses the uuid; the integer id never leaves the server, because a
             * sequential key in a URL leaks order volume and invites walking
             * the range to read strangers' orders.
             */
            $table->uuid('uuid')->unique();

            /*
             * The human-readable reference: what a customer quotes on the phone
             * and what appears on the invoice. Distinct from the uuid because
             * nobody reads a uuid aloud.
             *
             * Unique, and generated with a random component rather than a
             * sequence — see OrderNumberGenerator. A guessable order number is
             * a problem for guest order lookup, where the number plus an email
             * is the credential.
             */
            $table->string('order_number', 32)->unique();

            /*
             * The customer, when they have an account.
             *
             * nullOnDelete rather than cascade: deleting a customer must never
             * erase the store's sales history. The order survives with its
             * captured contact details intact, which is also what the store's
             * own accounting and tax obligations require.
             */
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            /*
             * Contact details captured at placement.
             *
             * Duplicated from the user record on purpose. They are the details
             * *this order* was confirmed against — the address the confirmation
             * went to, the number the courier will ring. A customer editing
             * their profile must not rewrite the history of a delivered order.
             */
            $table->string('customer_name', 191);
            $table->string('customer_email', 191);
            $table->string('customer_phone', 32)->nullable();

            /** Whether this was placed without an account. */
            $table->boolean('is_guest')->default(false);

            /*
             * Not indexed inline. Both get a composite index further down —
             * (status, created_at) — because the admin queue always filters by
             * status *and* sorts by date, and a single-column index leaves the
             * sort to a filesort over the matches. A bare `->index()` here
             * would add a redundant second index whose leading column the
             * composite already covers.
             */
            $table->string('status', 32);
            $table->string('payment_status', 32);

            $table->string('payment_method', 32);

            /*
             * The chosen delivery service.
             *
             * restrictOnDelete: an order references the method it was shipped
             * by, and retiring a service must not silently detach the record of
             * how a parcel was sent. Methods are deactivated, not deleted.
             */
            $table->foreignId('shipping_method_id')
                ->nullable()
                ->constrained('shipping_methods')
                ->restrictOnDelete();

            /*
             * The method's name, copied at placement.
             *
             * The same snapshot rule as prices: renaming "Standard" to
             * "Economy" next year must not rewrite what last year's invoices
             * say the customer chose.
             */
            $table->string('shipping_method_name', 128)->nullable();

            /*
             * Money. All minor units, all computed server-side by OrderService.
             *
             * Every component is stored rather than derived on read, because an
             * invoice must be reproducible exactly. Recomputing tax from a rate
             * setting would silently restate historical invoices the day
             * somebody edits the rate.
             *
             * The identity that must always hold:
             *   total = subtotal - discount + tax + shipping
             */
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('discount_total')->default(0);
            $table->unsignedBigInteger('tax_total')->default(0);
            $table->unsignedBigInteger('shipping_total')->default(0);
            $table->unsignedBigInteger('grand_total');

            /*
             * How much has actually been returned to the customer. Drives the
             * Refunded versus PartiallyRefunded distinction, and is the figure
             * a further refund is checked against so the store cannot refund
             * more than it took.
             */
            $table->unsignedBigInteger('refunded_total')->default(0);

            /*
             * The currency and tax rate in force at placement, captured for the
             * same reason as the amounts. `currency` is stored per order because
             * a store that changes currency must not have its old orders silently
             * reinterpreted in the new one.
             */
            $table->string('currency', 3)->default('USD');
            $table->decimal('tax_rate', 8, 4)->default(0);

            /** The coupon code presented, if any. Discounts land in `discount_total`. */
            $table->string('coupon_code', 64)->nullable();

            /*
             * Notes. Two fields, two audiences, never merged.
             *
             * `customer_note` is what the shopper wrote at checkout — "leave
             * with the neighbour". `admin_note` is internal. Keeping them apart
             * is what lets the packing slip print one and not the other; a
             * single notes column would eventually put a staff comment about a
             * customer onto a document that goes in their box.
             */
            $table->text('customer_note')->nullable();
            $table->text('admin_note')->nullable();

            /*
             * Duplicate-order prevention. See the class docblock.
             *
             * Nullable so orders created by other paths (an admin placing one by
             * hand, a seeder) need not invent a key; unique so that when a key
             * *is* present, the database enforces one order per key.
             */
            $table->string('idempotency_key', 64)->nullable()->unique();

            /*
             * Provenance, for fraud review and support. The cart the order came
             * from is kept as a plain integer rather than a foreign key: carts
             * are pruned, and an order must not be deleted or blocked from
             * deletion by that housekeeping.
             */
            $table->unsignedBigInteger('cart_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            /*
             * Lifecycle timestamps.
             *
             * Stored as their own columns rather than derived by querying the
             * status history, because "when did this ship?" is asked by every
             * list view and reporting query, and a correlated subquery per row
             * is how an orders index page becomes slow.
             */
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            /** Carrier tracking, set when the order ships. */
            $table->string('tracking_number', 128)->nullable();
            $table->string('tracking_url', 512)->nullable();

            $table->timestamps();

            /*
             * Soft deletes. An order is never truly removed — accounting, tax,
             * and dispute resolution all need it to exist. "Delete" in the admin
             * panel means "hide from the working list".
             */
            $table->softDeletes();

            /*
             * Indexes for the queries the admin panel and account area actually
             * run.
             */

            // "My orders", newest first.
            $table->index(['user_id', 'created_at'], 'orders_user_index');

            // Guest order lookup, and the support desk searching by email.
            $table->index('customer_email', 'orders_email_index');

            // The admin queue: orders in a status, newest first. Composite
            // rather than two single-column indexes, because filtering by
            // status and sorting by date is one query, and a single-column
            // index on status leaves the sort to a filesort over the matches.
            $table->index(['status', 'created_at'], 'orders_status_index');
            $table->index(['payment_status', 'created_at'], 'orders_payment_status_index');

            // Revenue reporting over a date range.
            $table->index('placed_at', 'orders_placed_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
