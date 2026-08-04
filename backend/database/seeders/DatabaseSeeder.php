<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Root seeder.
 *
 * Every child seeder is idempotent, so `php artisan db:seed` is safe to run
 * repeatedly against any environment including production.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SettingsSeeder::class,
            MenuSeeder::class,

            // Order matters: roles must exist before the Super Admin can be
            // assigned one.
            RolesAndPermissionsSeeder::class,
            SuperAdminSeeder::class,

            // Variant vocabulary (Size, Colour). Ordinary editable rows, not
            // fixtures the code depends on — they exist so a fresh install can
            // build a variable product without defining the vocabulary first.
            AttributeSeeder::class,

            // Sample products. Skips itself in production, where the catalog
            // belongs to the operator.
            CatalogDemoSeeder::class,

            /*
             * Storefront content.
             *
             * The six legally-expected pages are seeded as drafts with visibly
             * unwritten bodies, and a default homepage layout is created so a
             * fresh install renders a real page rather than an empty one. Both
             * are ordinary editable rows — nothing in the code depends on any
             * particular page or section existing.
             */
            CmsPageSeeder::class,
            HomepageSeeder::class,
        ]);
    }
}
