<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff accounts, stored separately from customers.
 *
 * A separate table rather than a flag on `users` is a deliberate security
 * boundary: privilege escalation from customer to staff would require
 * inserting a row into a different table, not flipping a column. A mass
 * assignment bug, a careless `update()`, or a compromised customer-facing
 * endpoint therefore cannot produce an administrator.
 *
 * It also keeps the two authentication flows genuinely independent — separate
 * guards, separate password reset brokers, separate Sanctum token owners.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table): void {
            $table->id();

            // Public identifier. Admin management endpoints address staff by
            // uuid so sequential ids are not exposed or enumerable.
            $table->uuid('uuid')->unique();

            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // Deactivation is reversible and preserves the audit trail;
            // deletion is not. Both are supported — this flag is the former.
            $table->boolean('is_active')->default(true);

            $table->string('phone', 32)->nullable();
            $table->string('avatar_path')->nullable();

            // Observability for account compromise: a login from an unexpected
            // address, or an account dormant for months suddenly active.
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            // Forces a password change at next login. Set when a Super Admin
            // creates an account with a generated password.
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('password_changed_at')->nullable();

            $table->rememberToken();
            $table->timestamps();

            // Soft delete so a departed administrator's actions remain
            // attributable in audit history.
            $table->softDeletes();

            // Covers the login lookup, which filters on both columns.
            $table->index(['is_active', 'email']);
        });

        // Separate broker table from the customers': a reset token issued for
        // a staff account must never be redeemable against a customer account
        // that happens to share an email address.
        Schema::create('admin_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_password_reset_tokens');
        Schema::dropIfExists('admins');
    }
};
