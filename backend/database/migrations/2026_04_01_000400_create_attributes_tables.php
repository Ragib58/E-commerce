<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dynamic variant attributes and their permitted values.
 *
 * "Size" and "Colour" are rows, not columns and not an enum. An operator adding
 * "Material: cotton | linen" is an INSERT from the admin panel — no migration,
 * no deploy. That is the whole point of this pair of tables: the set of things a
 * product can vary by is data.
 *
 * Values are normalised into their own table rather than stored as JSON on the
 * attribute so a variant can foreign-key to one. That is what makes "show me
 * every product available in Red" an indexed join instead of a JSON scan, and
 * what stops a typo creating a second, invisible shade of red.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attributes', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();

            /*
             * How the storefront renders the chooser: swatches for colour,
             * buttons for size, a dropdown for a long list. Presentation is
             * configured per attribute rather than hardcoded per name, so a
             * new attribute picks its own control without a frontend change.
             */
            $table->string('display_type', 24)->default('button');

            // Whether this attribute participates in the faceted filter rail.
            $table->boolean('is_filterable')->default(true);

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_filterable', 'sort_order'], 'attributes_filter_index');
        });

        Schema::create('attribute_values', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('attribute_id')
                ->constrained('attributes')
                ->cascadeOnDelete();

            $table->string('value');
            $table->string('slug');

            // Hex code for swatch rendering. Only meaningful for colour-like
            // attributes; null for everything else.
            $table->string('colour_code', 9)->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // Slugs are unique per attribute, not globally: "Colour: small" and
            // "Size: small" are unrelated values that may legitimately coexist.
            $table->unique(['attribute_id', 'slug'], 'attribute_values_unique');
            $table->index(['attribute_id', 'sort_order'], 'attribute_values_ordered_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_values');
        Schema::dropIfExists('attributes');
    }
};
