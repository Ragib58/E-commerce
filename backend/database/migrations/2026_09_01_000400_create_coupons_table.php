<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discount coupons.
 *
 * ## Every rule is a column, and every column is checked server-side
 *
 * The brief lists eleven rule types. They are stored as data rather than
 * expressed in code because a store invents new promotions constantly, and a
 * coupon whose rules live in a class is a coupon that needs a deploy to create.
 *
 * What is *not* negotiable is where they are enforced. `CouponService` computes
 * the discount from these columns and the catalog; no request field reaches a
 * money column, exactly as with cart prices and order totals. A client says
 * *which code* it is presenting — nothing else.
 *
 * ## Why `max_discount` exists
 *
 * A percentage coupon without a ceiling is an unbounded liability. "20% off"
 * costs 200 on a 1000 order and 200,000 on a million-taka one, and the person
 * who created it was thinking about the first case. The column is nullable
 * because a fixed-amount coupon needs no ceiling, but the admin request warns
 * when a percentage coupon is created without one.
 *
 * ## Usage counters are here; usage *records* are separate
 *
 * `used_count` is a denormalised total, incremented under a row lock at
 * redemption. The per-redemption rows live in `coupon_usages`, which is what
 * makes per-user limits enforceable and what an audit reads. Keeping both is
 * deliberate: "has this coupon hit its global limit" is asked on every checkout
 * and must not be a COUNT over a growing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
             * The code a shopper types.
             *
             * Stored upper-cased and uniquely indexed. Case-insensitivity is
             * handled by normalising on write and on lookup rather than by a
             * case-insensitive collation, so the behaviour is identical on
             * MySQL and on the SQLite the test suite runs against.
             */
            $table->string('code', 64)->unique();

            /** Shown to the shopper when the coupon applies. */
            $table->string('name', 128);
            $table->string('description', 512)->nullable();

            /** `percentage` or `fixed` — see App\Enums\CouponType. */
            $table->string('type', 16);

            /*
             * For a percentage coupon: the percentage, as a decimal (10.5 = 10.5%).
             * For a fixed coupon: minor units.
             *
             * One column for two meanings is a compromise. The alternative —
             * `percentage` and `fixed_amount` columns, one always null — reads
             * more clearly but makes every query and every form branch on type
             * anyway, and invites the state where both are set.
             */
            $table->decimal('value', 12, 4);

            /*
             * Ceiling on a percentage discount, in minor units. See the class
             * docblock: without it a percentage coupon is unbounded.
             */
            $table->unsignedBigInteger('max_discount')->nullable();

            /*
             * Order subtotal required before the coupon applies at all.
             *
             * Checked against the subtotal *before* the discount, which is the
             * only reading that terminates: checking it after would let a
             * coupon push an order below its own minimum and then disqualify
             * itself.
             */
            $table->unsignedBigInteger('min_order_amount')->nullable();

            /*
             * Whether the discount also covers shipping.
             *
             * Separate from the discount itself because "free shipping" is a
             * distinct promotion from "money off", and expressing it as a fixed
             * discount equal to the shipping cost breaks the moment the
             * shopper changes delivery method.
             */
            $table->boolean('free_shipping')->default(false);

            /*
             * Scope.
             *
             * Null in all three means "applies to the whole order". When any is
             * set, the discount is computed against the *matching lines only* —
             * a 20%-off-electronics coupon on a mixed basket must not discount
             * the groceries.
             *
             * Stored as pivot tables rather than JSON id arrays because these
             * are queried in both directions: an admin asks "which coupons
             * apply to this product", and a JSON column cannot answer that
             * without a full scan.
             */
            $table->boolean('applies_to_all')->default(true);

            /*
             * Restricted to a customer's first order.
             *
             * "First" means no *previous* order in a revenue-bearing state —
             * cancelled orders do not consume the entitlement, because a
             * customer whose first attempt failed has not had their welcome
             * discount.
             */
            $table->boolean('first_order_only')->default(false);

            /*
             * Restricted to named customers.
             *
             * The flag is stored rather than inferred from the pivot being
             * non-empty, so an operator can create a user-restricted coupon and
             * add the users afterwards without it being briefly valid for
             * everyone.
             */
            $table->boolean('user_restricted')->default(false);

            /*
             * Validity window. Both nullable and both meaningful:
             * a null start is "live now", a null end is "no expiry".
             */
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            /*
             * How many times it may be redeemed in total, and per customer.
             *
             * Null means unlimited in both cases — deliberately distinct from
             * 0, which would mean "may never be used" and is a state an
             * operator reaches only by mistake.
             */
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_user_limit')->nullable();

            /*
             * Redemptions so far. Denormalised on purpose — see the class
             * docblock — and only ever written under a row lock.
             */
            $table->unsignedInteger('used_count')->default(0);

            $table->boolean('is_active')->default(true);

            /*
             * Whether the coupon can be found without knowing the code.
             *
             * A public promotion appears on a "current offers" endpoint; a
             * private one is only usable by someone who was sent it. Defaults
             * to false so a coupon created for a single customer is not
             * accidentally advertised to everyone.
             */
            $table->boolean('is_public')->default(false);

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('admins')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            /*
             * The validity query: active coupons inside their window.
             *
             * Composite because every lookup filters on all three, and a
             * single-column index on `is_active` would leave the date
             * comparison to a scan over every active coupon.
             */
            $table->index(['is_active', 'starts_at', 'expires_at'], 'coupons_validity_index');
            $table->index(['is_public', 'is_active'], 'coupons_public_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
