<?php

declare(strict_types=1);

use App\Enums\SettingGroup;
use App\Enums\SettingType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dynamic, admin-managed configuration.
 *
 * Every value the storefront renders as branding — company name, logo,
 * favicon, colours, contact details, social links — lives here rather than in
 * config files or frontend constants. Adding a new brandable field is an
 * INSERT, not a migration.
 *
 * `type` and `group` are stored as strings validated against PHP enums rather
 * than native DB enums: adding a case must not require an ALTER TABLE, and the
 * constraint is enforced consistently across MySQL and PostgreSQL by the cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();

            // Dot-namespaced identifier, e.g. `branding.logo`, `theme.primary_color`.
            $table->string('key', 191)->unique();

            // Nullable: an unset logo is a legitimate state, distinct from "".
            $table->text('value')->nullable();

            $table->string('type', 32)->default(SettingType::String->value);
            $table->string('group', 32)->default(SettingGroup::General->value);

            // Admin-facing presentation metadata, so the panel can render a
            // labelled form without a hardcoded field map.
            $table->string('label')->nullable();
            $table->string('description', 500)->nullable();

            // Gates exposure through the unauthenticated storefront API.
            $table->boolean('is_public')->default(false);

            // Locked settings are seeded infrastructure keys the admin UI must
            // not allow deleting (it may still edit the value).
            $table->boolean('is_locked')->default(false);

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            // The public settings endpoint filters on (is_public, group) and
            // orders by sort_order — this covers that access path entirely.
            $table->index(['is_public', 'group', 'sort_order'], 'settings_public_group_sort_index');
            $table->index('group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
