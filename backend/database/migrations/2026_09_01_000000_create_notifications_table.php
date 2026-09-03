<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's standard database-notification store.
 *
 * Deliberately unmodified from the framework's own shape — the `database`
 * channel, `Notification::send()`, `$notifiable->notifications()`, and every
 * related helper assume exactly these columns. One table serves both Admin and
 * User via the polymorphic `notifiable_type` / `notifiable_id` pair, which is
 * what lets "new order" (an admin notification) and "order shipped" (a
 * customer one) share the same delivery mechanism and the same
 * mark-as-read/unread API without two parallel implementations.
 *
 * Ordered first among this phase's migrations (`_000000_`) because
 * NotificationPreferenceService and every queued notification below depend on
 * it existing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
