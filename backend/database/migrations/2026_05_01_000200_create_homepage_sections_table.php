<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The homepage, as data.
 *
 * One row per section, ordered by `sort_order`. The page the storefront renders
 * is exactly the ordered list of enabled, in-window rows here — there is no
 * hardcoded section list anywhere in the frontend, so adding a rail is an
 * INSERT and reordering the page is an UPDATE.
 *
 * Why one table and not eleven:
 *
 *   Every section type shares the same lifecycle — enable, order, schedule —
 *   and differs only in payload. Eleven tables would duplicate that lifecycle
 *   eleven times and make "the homepage in display order" a UNION across all of
 *   them, which cannot be indexed or paginated sensibly. The differing payload
 *   lives in `settings`, a JSON column whose shape each SectionType declares.
 *
 * What `settings` deliberately does NOT contain: resolved catalog content. A
 * featured-products section stores `{"limit": 8}`, not eight product ids
 * snapshotted at save time — otherwise unpublishing a product would leave it
 * advertised on the homepage until someone re-saved the section.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homepage_sections', function (Blueprint $table): void {
            $table->id();

            // The SectionType case. Not a DB enum: adding a section type would
            // then require a migration and a table lock, whereas the whole
            // point of this module is that content structure is editable.
            $table->string('type', 40)->index();

            // Operator-facing name in the builder ("Summer hero"), distinct
            // from `heading`, which shoppers see. Keeping them separate lets an
            // admin label a section for themselves without printing that label
            // on the storefront.
            $table->string('name');

            $table->string('heading')->nullable();
            $table->string('subheading', 512)->nullable();

            /*
             * Type-specific payload. Defaults come from
             * SectionType::defaultSettings() at creation, so a newly added
             * section renders sensibly before anyone configures it.
             */
            $table->json('settings')->nullable();

            // Presentation escape hatches an operator can set without a
            // deploy. Constrained by validation to safe tokens/hex, never
            // interpolated into a stylesheet verbatim.
            $table->string('background_color', 32)->nullable();
            $table->string('container_width', 24)->nullable();

            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            // The scheduling window. Both ends nullable and meaning
            // "unbounded"; compared in SQL so an out-of-window section is never
            // loaded, not merely hidden after loading.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            /*
             * The storefront's single homepage query: enabled sections whose
             * window contains now, in display order.
             *
             * `is_enabled` leads because it is the most selective equality
             * predicate and the one that stays useful for the ORDER BY: with
             * `sort_order` immediately after it, MySQL can walk the index in
             * order and skip the filesort entirely for the common case where
             * nothing is scheduled.
             */
            $table->index(['is_enabled', 'sort_order'], 'homepage_sections_render_index');
            $table->index(['starts_at', 'ends_at'], 'homepage_sections_window_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homepage_sections');
    }
};
