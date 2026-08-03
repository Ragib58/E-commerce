<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product gallery images and video.
 *
 * A separate table rather than a JSON column on `products`: gallery entries are
 * reordered, captioned, and individually deleted, and one of them is the
 * thumbnail. Each of those is a row operation. As JSON, every reorder would be
 * a read-modify-write of the whole array — which loses a concurrent edit.
 *
 * The thumbnail is a flag here rather than a column on `products` so promoting
 * a gallery image to thumbnail moves a boolean instead of duplicating a path
 * that then has to be kept in sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_media', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            /*
             * Optionally scoped to a variant, so selecting "Red" can swap the
             * gallery to the red photographs. Null means the image belongs to
             * the product as a whole and shows for every variant.
             */
            $table->foreignId('product_variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->cascadeOnDelete();

            // 'image' or 'video'. Video rows carry a URL in `path` rather than
            // a stored file — hosting video is a CDN's job, not this app's.
            $table->string('type', 16)->default('image');

            $table->string('path', 2048);
            $table->string('alt_text')->nullable();

            // Exactly one per product; enforced in the service for the same
            // portability reason as the default variant.
            $table->boolean('is_thumbnail')->default(false);

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort_order'], 'product_media_ordered_index');
            $table->index(['product_id', 'is_thumbnail'], 'product_media_thumbnail_index');
            $table->index('product_variant_id', 'product_media_variant_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_media');
    }
};
