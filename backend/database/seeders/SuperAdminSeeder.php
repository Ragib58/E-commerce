<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RoleType;
use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates the bootstrap Super Admin.
 *
 * Every installation needs one account able to create the others. The
 * credential handling here is deliberately awkward, because a well-known
 * default admin password is one of the most reliably exploited weaknesses in
 * self-hosted software:
 *
 *   - The password comes from SUPER_ADMIN_PASSWORD when set.
 *   - Otherwise a random one is generated and printed once to the console.
 *   - In production, a generated password additionally forces rotation at
 *     first sign-in.
 *
 * There is no hardcoded fallback such as "password" or "admin123", so an
 * installation cannot accidentally ship with known credentials.
 *
 * Idempotent: if the account already exists it is left untouched, so
 * re-running `db:seed` never resets a live administrator's password.
 */
final class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower(trim((string) env('SUPER_ADMIN_EMAIL', 'admin@example.com')));

        /** @var Admin|null $existing */
        $existing = Admin::query()->where('email', $email)->first();

        if ($existing !== null) {
            // Ensure the role is attached even if a previous run was
            // interrupted between creating the account and assigning it.
            if (! $existing->hasRole(RoleType::SuperAdmin)) {
                $existing->syncRoles([RoleType::SuperAdmin->value]);
                $this->command?->info("Re-attached the Super Admin role to {$email}.");
            }

            $this->command?->info("Super Admin already exists ({$email}); left unchanged.");

            return;
        }

        $configuredPassword = env('SUPER_ADMIN_PASSWORD');
        $isGenerated = blank($configuredPassword);

        $password = $isGenerated
            ? Str::password(20, letters: true, numbers: true, symbols: true, spaces: false)
            : (string) $configuredPassword;

        $admin = Admin::query()->create([
            'name' => (string) env('SUPER_ADMIN_NAME', 'Super Admin'),
            'email' => $email,
            'password' => $password,
            'is_active' => true,
            'email_verified_at' => now(),
            // A generated password has necessarily been displayed on a
            // terminal and may be in shell history or CI logs, so it must not
            // remain valid as a long-term credential in production.
            'must_change_password' => $isGenerated && app()->isProduction(),
        ]);

        $admin->syncRoles([RoleType::SuperAdmin->value]);

        $this->announce($email, $password, $isGenerated);
    }

    private function announce(string $email, string $password, bool $isGenerated): void
    {
        if ($this->command === null) {
            return;
        }

        $this->command->newLine();
        $this->command->info('Super Admin account created.');
        $this->command->line("  Email:    {$email}");

        if ($isGenerated) {
            // Printed exactly once. Nothing stores the plaintext, so a lost
            // password can only be recovered via the reset flow.
            $this->command->line("  Password: {$password}");
            $this->command->newLine();
            $this->command->warn('This password is shown only once. Store it securely now.');

            if (app()->isProduction()) {
                $this->command->warn('It must be changed at first sign-in.');
            }
        } else {
            $this->command->line('  Password: (from SUPER_ADMIN_PASSWORD)');
        }

        $this->command->newLine();
    }
}
