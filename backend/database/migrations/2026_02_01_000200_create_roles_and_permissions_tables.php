<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role-based access control schema.
 *
 * Three tables plus two pivots:
 *   roles                  — named permission bundles
 *   permissions            — granular capabilities
 *   permission_role        — which capabilities a role grants
 *   admin_role             — which roles a staff member holds
 *   admin_permission       — per-admin overrides, grant or revoke
 *
 * The direct admin_permission table exists because roles alone cannot express
 * "this Product Manager may also issue refunds" without inventing a bespoke
 * role for one person. It supports revocation as well as granting, so an
 * exception can subtract from a role rather than only add to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();

            // Machine name, matching RoleType for seeded roles. Custom roles
            // created by an operator get a slugified name.
            $table->string('name', 64)->unique();

            $table->string('label');
            $table->string('description', 500)->nullable();

            // Ranking used to prevent an admin acting on a peer or superior.
            $table->unsignedSmallInteger('level')->default(0);

            // System roles cannot be deleted — removing "Super Admin" would
            // permanently lock everyone out of the authorization system.
            $table->boolean('is_system')->default(false);

            $table->timestamps();

            $table->index('level');
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('label');
            $table->string('group', 64)->default('General');
            $table->string('description', 500)->nullable();
            $table->timestamps();

            // The admin panel renders the permission matrix grouped.
            $table->index('group');
        });

        Schema::create('permission_role', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();

            $table->timestamps();

            // Prevents a duplicate grant, which would otherwise make
            // revocation ambiguous.
            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('admin_role', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();

            // Attribution for the audit trail. Nullable and nullOnDelete so
            // removing the granting admin does not cascade away the grant.
            $table->foreignId('assigned_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            $table->timestamps();

            $table->unique(['admin_id', 'role_id']);
        });

        Schema::create('admin_permission', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();

            // false = explicit revoke, which overrides any role that grants
            // this permission. Lets an exception subtract, not only add.
            $table->boolean('is_granted')->default(true);

            $table->foreignId('assigned_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            $table->timestamps();

            $table->unique(['admin_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_permission');
        Schema::dropIfExists('admin_role');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
