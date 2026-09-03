<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-account, per-notification-type opt-outs.
 *
 * ## Opt-out, not opt-in
 *
 * There is no row for "wants order-placed emails" — the absence of a row means
 * the default (on) applies, and a row here means an override. Modelling it as
 * opt-in would mean every new account needs its preferences seeded before a
 * single notification can be sent, and a bug in that seeding silently mutes
 * everyone. Absence meaning "on" is the fail-safe direction for transactional
 * mail: a shopper missing their order confirmation is a support ticket; one who
 * gets an email they didn't ask to be spared is, at worst, an annoyance.
 *
 * ## Transactional types cannot be muted through this table
 *
 * `NotificationPreferenceService` enforces that certain types — order placed,
 * payment failed — are not preference-checked at all before sending: the row
 * can exist, but it is never consulted for those keys. A customer can turn off
 * marketing noise; they cannot accidentally turn off the email that tells them
 * their card was declined. That distinction lives in code (see
 * `NotificationType::isMutable()`), not in this schema, because "which types
 * are mutable" is a product decision that changes independently of the storage
 * shape.
 *
 * ## One polymorphic table for two account types
 *
 * Admins and customers both have preferences, and both are covered by
 * `notifiable_type` / `notifiable_id` rather than two tables. The alternative —
 * `admin_notification_preferences` and `user_notification_preferences` — is two
 * copies of every query this table answers, for a distinction (which table the
 * account lives in) that the preference itself does not care about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();

            $table->morphs('notifiable');

            /** A App\Enums\NotificationType value — 'order_placed', 'low_stock', etc. */
            $table->string('type', 64);

            /** A delivery channel — 'mail' or 'database'. */
            $table->string('channel', 32);

            /*
             * false means muted. There is no true row: a preference is only
             * ever written when an account turns something OFF, so the table
             * stays small and a fresh install has zero rows in it — nothing to
             * seed, nothing to migrate when a new notification type is added.
             */
            $table->boolean('is_enabled')->default(false);

            $table->timestamps();

            $table->unique(
                ['notifiable_type', 'notifiable_id', 'type', 'channel'],
                'notification_preferences_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
