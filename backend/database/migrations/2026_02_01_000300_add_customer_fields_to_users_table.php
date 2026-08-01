<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends `users` into a full customer account, and retires `is_admin`.
 *
 * Phase 1 seeded `is_admin` as a "coarse admin flag ... replaced by a
 * role/permission model in the authentication phase". That replacement is
 * this: staff now live in the `admins` table with roles and permissions, so
 * the flag is not merely unused but actively dangerous — leaving a writable
 * boolean that once meant "is an administrator" invites a future escalation
 * bug. It is dropped rather than deprecated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 32)->nullable()->after('email');
            $table->string('avatar_path')->nullable()->after('phone');
            $table->date('date_of_birth')->nullable()->after('avatar_path');

            // Customers can be suspended without deleting their order history.
            $table->boolean('is_active')->default(true)->after('date_of_birth');

            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->timestamp('password_changed_at')->nullable()->after('last_login_ip');

            $table->index(['is_active', 'email']);
        });

        // Dropped in its own schema call: combining a column drop with adds in
        // one Blueprint is not portable across MySQL and PostgreSQL.
        if (Schema::hasColumn('users', 'is_admin')) {
            Schema::table('users', function (Blueprint $table): void {
                // The index created in Phase 1 must go first; PostgreSQL will
                // not drop a column that an index still references.
                $table->dropIndex(['is_admin']);
                $table->dropColumn('is_admin');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_admin')->default(false)->index();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['is_active', 'email']);

            $table->dropColumn([
                'phone',
                'avatar_path',
                'date_of_birth',
                'is_active',
                'last_login_at',
                'last_login_ip',
                'password_changed_at',
            ]);
        });
    }
};
