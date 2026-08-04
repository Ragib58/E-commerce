<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editorial pages — About, Contact, Privacy, Terms, Refunds, Shipping.
 *
 * These are ordinary rows, not files: the six pages a store legally needs are
 * seeded, but nothing in the code depends on their existence or their slugs.
 * The storefront resolves /p/{slug} against this table, so an operator adding a
 * "Size guide" page needs no deploy.
 *
 * `is_system` marks the seeded six. It does not make them uneditable — an
 * operator must be able to write their own refund policy — it only stops them
 * being *deleted*, because a footer link to a missing privacy policy is a
 * compliance problem rather than a cosmetic one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_pages', function (Blueprint $table): void {
            $table->id();

            $table->string('title');

            // The storefront URL. Unique across live and soft-deleted rows is
            // enforced in the model's slug generator rather than here, so
            // restoring a trashed page cannot collide with a replacement.
            $table->string('slug')->unique();

            $table->text('excerpt')->nullable();

            // Rich text (HTML). Sanitised on write by CmsPageService — an
            // admin authoring tool is still an injection vector, since the
            // panel and the storefront share a browser origin model.
            $table->longText('content')->nullable();

            // Disk-relative path, never an absolute URL.
            $table->string('featured_image')->nullable();

            $table->string('seo_title')->nullable();
            $table->string('seo_description', 512)->nullable();
            $table->string('seo_keywords', 512)->nullable();
            // Overrides the site-wide OG image for this page's social cards.
            $table->string('og_image')->nullable();
            // Excludes a page from search indexes without unpublishing it —
            // needed for pages like "order confirmed" that must be reachable
            // but should never appear in results.
            $table->boolean('is_indexable')->default(true);

            $table->string('status', 16)->default('draft');

            // Protects the seeded legal pages from deletion. See the class
            // docblock: this is a delete guard, not a read-only flag.
            $table->boolean('is_system')->default(false);

            $table->unsignedInteger('sort_order')->default(0);

            // Scheduling window, identical in meaning to banners and sections.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // Distinct from created_at: the moment the page went live, which is
            // what a "last updated" notice on a privacy policy should show.
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'starts_at', 'ends_at'], 'cms_pages_live_index');
            $table->index(['status', 'sort_order'], 'cms_pages_listing_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_pages');
    }
};
