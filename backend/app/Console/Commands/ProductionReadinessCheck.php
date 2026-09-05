<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Cache\TaggableStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-flight check for a production deployment.
 *
 * ## Why this is a command rather than a documented checklist
 *
 * Every item below is a setting that is *correct* in development and dangerous
 * in production: debug output on, cookies not marked secure, a wide-open CORS
 * origin list. A written checklist catches those exactly as often as somebody
 * remembers to read it, which under deadline is rarely.
 *
 * Run as `php artisan app:production-check`. It exits non-zero when anything
 * fails, so a deploy pipeline can gate on it and the question stops depending
 * on human memory.
 *
 * Failures are things that would be unsafe or broken in production. Warnings
 * are things that are usually wrong but have legitimate exceptions, so they do
 * not fail the build on their own.
 */
final class ProductionReadinessCheck extends Command
{
    protected $signature = 'app:production-check
                            {--strict : Treat warnings as failures.}';

    protected $description = 'Verify configuration and infrastructure are safe for production.';

    /** @var array<int, array{level: string, area: string, message: string}> */
    private array $findings = [];

    public function handle(): int
    {
        $this->components->info('Production readiness check');

        $this->checkApplication();
        $this->checkSecurity();
        $this->checkDatabase();
        $this->checkCacheAndQueue();
        $this->checkPayments();

        return $this->report();
    }

    private function checkApplication(): void
    {
        $env = (string) config('app.env');

        if ($env !== 'production') {
            $this->warn_('Application', "APP_ENV is '{$env}'. These checks assume a production target.");
        }

        // The single most damaging misconfiguration: debug pages render stack
        // traces, environment variables, and database credentials to anyone
        // who can trigger a 500.
        if (config('app.debug') === true) {
            $this->fail_('Application', 'APP_DEBUG is true. Set APP_DEBUG=false — debug output leaks credentials and stack traces.');
        }

        if (config('app.key') === null || config('app.key') === '') {
            $this->fail_('Application', 'APP_KEY is empty. Encrypted values and signed URLs cannot be trusted.');
        }

        $url = (string) config('app.url');

        if (! str_starts_with($url, 'https://')) {
            $this->fail_('Application', "APP_URL is '{$url}'. Production must be https:// so generated links and signed URLs are secure.");
        }
    }

    private function checkSecurity(): void
    {
        if (config('session.secure') !== true) {
            $this->fail_('Security', 'SESSION_SECURE_COOKIE is false. The admin session cookie would travel over plain HTTP.');
        }

        if (config('session.http_only') !== true) {
            $this->fail_('Security', 'Session cookies must be HttpOnly so script cannot read them.');
        }

        $sameSite = (string) config('session.same_site', '');

        if (! in_array($sameSite, ['lax', 'strict'], strict: true)) {
            $this->warn_('Security', "SESSION_SAME_SITE is '{$sameSite}'. 'lax' or 'strict' is expected for the admin panel.");
        }

        /** @var array<int, string> $origins */
        $origins = (array) config('cors.allowed_origins', []);

        if (in_array('*', $origins, strict: true)) {
            $this->fail_('Security', 'CORS allows any origin (*). Name the storefront origins explicitly.');
        }

        foreach ($origins as $origin) {
            if (str_starts_with((string) $origin, 'http://')) {
                $this->warn_('Security', "CORS allows a plaintext origin: {$origin}");
            }
        }

        if ($origins === [] && (array) config('cors.allowed_origins_patterns', []) === []) {
            $this->warn_('Security', 'No CORS origins configured — the storefront will be unable to call the API.');
        }
    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->fail_('Database', 'Cannot connect: '.$e->getMessage());

            return;
        }

        // A pending migration in production is a schema the code does not
        // match — usually a 500 on the first request that touches it.
        try {
            $pending = collect(app('migrator')->getMigrationFiles(database_path('migrations')))
                ->keys()
                ->diff(app('migrator')->getRepository()->getRan())
                ->count();

            if ($pending > 0) {
                $this->fail_('Database', "{$pending} migration(s) have not been run.");
            }
        } catch (\Throwable) {
            $this->warn_('Database', 'Could not determine migration status.');
        }

        foreach (['orders', 'products', 'payments', 'users'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->fail_('Database', "Core table `{$table}` is missing.");
            }
        }
    }

    private function checkCacheAndQueue(): void
    {
        $cacheStore = (string) config('cache.default');

        if (in_array($cacheStore, ['array', 'null'], strict: true)) {
            $this->fail_('Cache', "CACHE_STORE is '{$cacheStore}', which persists nothing. Use redis.");
        }

        try {
            Cache::put('production-check', '1', 5);

            if (Cache::get('production-check') !== '1') {
                $this->fail_('Cache', 'The cache store did not return what was written to it.');
            }

            Cache::forget('production-check');
        } catch (\Throwable $e) {
            $this->fail_('Cache', 'Cache is unreachable: '.$e->getMessage());
        }

        // Reporting and catalog caching both rely on tags, which the file and
        // database stores cannot do — they would silently degrade to
        // recomputing every dashboard aggregate per request.
        if (! Cache::getStore() instanceof TaggableStore) {
            $this->warn_('Cache', 'The cache store does not support tags; catalog and report caching will be disabled.');
        }

        $queue = (string) config('queue.default');

        if ($queue === 'sync') {
            $this->fail_('Queue', 'QUEUE_CONNECTION is sync. Notifications and emails would run inside the web request.');
        }

        if (! Schema::hasTable('failed_jobs')) {
            $this->warn_('Queue', 'No failed_jobs table — a failed notification would vanish without trace.');
        }
    }

    private function checkPayments(): void
    {
        /** @var array<string, array<string, mixed>> $gateways */
        $gateways = (array) config('payment.gateways', []);
        $enabled = 0;

        foreach ($gateways as $name => $gateway) {
            if (($gateway['enabled'] ?? false) !== true) {
                continue;
            }

            $enabled++;

            // A gateway enabled in sandbox on a production deploy takes real
            // orders and settles none of them.
            if (($gateway['sandbox'] ?? false) === true) {
                $this->fail_('Payments', "Gateway '{$name}' is enabled in sandbox mode.");
            }

            foreach (['secret_key', 'api_secret', 'store_password', 'app_secret'] as $credential) {
                if (array_key_exists($credential, $gateway) && in_array($gateway[$credential], [null, ''], strict: true)) {
                    $this->fail_('Payments', "Gateway '{$name}' is enabled but '{$credential}' is empty.");
                }
            }

            // Without it an inbound webhook cannot be authenticated, and the
            // handler correctly refuses every one — so payments silently stop
            // settling by webhook.
            if (array_key_exists('webhook_secret', $gateway) && in_array($gateway['webhook_secret'], [null, ''], strict: true)) {
                $this->warn_('Payments', "Gateway '{$name}' has no webhook secret; inbound webhooks will be rejected.");
            }
        }

        if ($enabled === 0) {
            $this->warn_('Payments', 'No payment gateway is enabled — the store cannot take money.');
        }
    }

    private function fail_(string $area, string $message): void
    {
        $this->findings[] = ['level' => 'fail', 'area' => $area, 'message' => $message];
    }

    private function warn_(string $area, string $message): void
    {
        $this->findings[] = ['level' => 'warn', 'area' => $area, 'message' => $message];
    }

    private function report(): int
    {
        $failures = array_values(array_filter($this->findings, static fn (array $f): bool => $f['level'] === 'fail'));
        $warnings = array_values(array_filter($this->findings, static fn (array $f): bool => $f['level'] === 'warn'));

        foreach ($failures as $finding) {
            $this->components->error("[{$finding['area']}] {$finding['message']}");
        }

        foreach ($warnings as $finding) {
            $this->components->warn("[{$finding['area']}] {$finding['message']}");
        }

        if ($failures === [] && $warnings === []) {
            $this->newLine();
            $this->components->info('All checks passed.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line(sprintf('  %d failure(s), %d warning(s).', count($failures), count($warnings)));

        if ($failures !== []) {
            return self::FAILURE;
        }

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}
