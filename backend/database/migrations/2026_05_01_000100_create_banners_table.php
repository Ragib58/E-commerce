<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed promotional imagery: hero slides, campaign strips, popups.
 *
 * Banners are their own entity rather than an array nested inside a homepage
 * section's JSON, for three reasons that only show up in operation:
 *
 *   - The same slide is often wanted in two places (a hero slide reused as a
 *     category header). Nested JSON forces a copy, and copies drift.
 *   - Scheduling a campaign means editing one row, not surgically patching a
 *     JSON array inside an unrelated section.
 *   - An image path in a JSON blob cannot be found by a query, so orphaned
 *     uploads could never be reclaimed.
 *
 * `image` is required but `mobile_image` is not: art direction for small
 * screens is an optimisation, and the renderer falls back to the primary image
 * rather than refusing to display a banner that lacks one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table): void {
            $table->id();

            $table->string('title');
            $table->string('subtitle')->nullable();

            // Disk-relative paths, never absolute URLs — see MediaService.
            $table->string('image');
            $table->string('mobile_image')->nullable();

            // Always stored, always rendered into <img alt>. Nullable in the
            // column only so an existing row is not blocked; the request layer
            // encourages it, and the resource falls back to the title.
            $table->string('alt_text')->nullable();

            $table->string('link_url', 512)->nullable();
            $table->string('link_label')->nullable();
            // Opens the link in a new tab. A campaign pointing at a partner
            // site usually wants this; an internal category link does not.
            $table->boolean('link_external')->default(false);

            $table->string('placement', 32)->index();
            $table->string('status', 16)->default('draft');

            $table->unsignedInteger('sort_order')->default(0);

            /*
             * The scheduling window, shared by every schedulable entity here.
             *
             * Both ends are nullable and mean "unbounded" — a banner with
             * neither is simply always live once published. Stored as UTC
             * timestamps and compared in SQL, never in PHP: filtering after
             * the fact would break pagination counts and force loading rows
             * the query should never have returned.
             */
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            /*
             * The storefront's only banner query: live banners for one
             * placement, in display order. Status precedes the date columns
             * because it is the more selective equality predicate; the ranges
             * cannot be used for lookup beyond the first of them, so
             * `sort_order` trails as a tiebreaker rather than a sort avoider.
             */
            $table->index(['placement', 'status', 'starts_at', 'ends_at'], 'banners_live_index');
            $table->index(['placement', 'sort_order'], 'banners_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
