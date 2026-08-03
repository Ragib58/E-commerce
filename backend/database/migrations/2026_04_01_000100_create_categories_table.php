<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product categories, nestable to unlimited depth.
 *
 * Depth is unbounded by design, but a naive adjacency list makes "every
 * descendant of this node" an N-query recursion. Two denormalised columns fix
 * that without a second table:
 *
 *   `path`  — the materialised ancestor chain ("/1/7/12/"). One indexed LIKE
 *             prefix scan returns an entire subtree in a single query.
 *   `depth` — the level, so a listing can cheaply exclude deep nodes or render
 *             indentation without walking parents.
 *
 * Both are maintained by the Category model, never written by hand. They are
 * derived data: if they were ever lost, they could be rebuilt from `parent_id`
 * alone, which is what keeps `parent_id` the single source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();

            // Restricted, not cascading: deleting a parent must not silently
            // destroy an entire product taxonomy. The service re-parents or
            // refuses, so the destruction is always an explicit decision.
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('categories')
                ->restrictOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // Disk-relative paths, never absolute URLs — see MediaService.
            $table->string('image')->nullable();
            $table->string('banner')->nullable();

            $table->string('meta_title')->nullable();
            $table->string('meta_description', 512)->nullable();

            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('sort_order')->default(0);

            // Materialised ancestry. Nullable only for the instant between
            // insert and the model's post-save path computation.
            $table->string('path', 512)->nullable();
            $table->unsignedSmallInteger('depth')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // The storefront's sidebar query: children of a node, live only,
            // in display order. Covers the sort so no filesort is needed.
            $table->index(['parent_id', 'status', 'sort_order'], 'categories_tree_index');

            // Subtree lookups scan this prefix.
            $table->index('path', 'categories_path_index');

            $table->index(['status', 'sort_order'], 'categories_listing_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
