<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed navigation menus and their nested items.
 *
 * The storefront resolves a menu by `location`, never by id, so an admin can
 * repoint the header at a different menu with no frontend change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('location', 32)->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['location', 'is_active']);
        });

        Schema::create('menu_items', function (Blueprint $table): void {
            $table->id();

            // Deleting a menu removes its items; they have no meaning alone.
            $table->foreignId('menu_id')
                ->constrained('menus')
                ->cascadeOnDelete();

            // Self-referential nesting. Deleting a parent removes its subtree.
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('menu_items')
                ->cascadeOnDelete();

            $table->string('label');

            // Nullable so an item can act as a non-navigable dropdown heading.
            $table->string('url', 2048)->nullable();

            $table->string('icon', 64)->nullable();
            $table->string('target', 16)->default('_self');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // Covers the tree-building query: items of a menu, by level, ordered.
            $table->index(['menu_id', 'parent_id', 'sort_order'], 'menu_items_tree_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menus');
    }
};
