<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manufacturers and labels a product can be attributed to.
 *
 * Flat by intent: a brand hierarchy ("Nike > Jordan") is a real thing, but
 * modelling it here would duplicate the category tree's machinery for a case
 * the storefront navigates by category anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo')->nullable();
            $table->text('description')->nullable();

            $table->string('meta_title')->nullable();
            $table->string('meta_description', 512)->nullable();

            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'name'], 'brands_listing_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
