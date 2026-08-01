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
        ]);
    }
}
