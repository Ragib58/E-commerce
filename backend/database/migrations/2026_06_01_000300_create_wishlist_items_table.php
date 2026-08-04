<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved products, per customer.
 *
 * Authenticated only, deliberately. A wishlist's whole value is that it
 * outlives the session — a guest wishlist stored server-side would be
 * indistinguishable from a cart in cost while being worth far less, since the
 * shopper has no way to return to it. Guests get a wishlist too, but it lives
 * in localStorage on the client and is merged into this table on sign-in.
 *
 * Compare is *not* stored here. Comparison is a within-session act — a shopper
 * lines up three kettles, picks one, and never wants that list again — so it
 * stays entirely client-side and costs the database nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlist_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * Restricted rather than cascading, matching cart_items: products
             * are soft-deleted in normal operation, and a force-delete that
             * silently emptied every shopper's saved list should be refused
             * rather than performed quietly.
             */
            $table->foreignId('product_id')
                ->constrained()
                ->restrictOnDelete();

            $table->timestamps();

            // A product is saved or it is not; saving it twice is meaningless.
            // The constraint makes the "add" endpoint idempotent for free.
            $table->unique(['user_id', 'product_id'], 'wishlist_items_unique');

            // The listing query: one customer's saved items, newest first.
            $table->index(['user_id', 'created_at'], 'wishlist_items_listing_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_items');
    }
};
