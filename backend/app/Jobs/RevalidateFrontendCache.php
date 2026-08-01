<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the Next.js storefront to drop cached content for the given tags.
 *
 * This is what makes "admin changes the logo, the site updates" work without a
 * redeploy: Laravel owns the data, Next.js caches it, and this job is the
 * invalidation signal between them.
 *
 * Queued and retried with backoff — the storefront may be mid-deploy when a
 * setting changes, and losing the invalidation would leave stale branding
 * cached until the TTL expired.
 */
final class RevalidateFrontendCache implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> Seconds to wait before each retry. */
    public array $backoff = [5, 30, 120];

    public int $timeout = 15;

    /**
     * @param  array<int, string>  $tags  Next.js cache tags to invalidate.
     * @param  array<int, string>  $changedKeys  Included for observability only.
     */
    public function __construct(
        private readonly array $tags,
        private readonly array $changedKeys = [],
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $url = (string) config('api.revalidation.url');
        $secret = config('api.revalidation.secret');

        if ($url === '' || blank($secret)) {
            // Misconfiguration, not a transient fault — retrying cannot help.
            Log::warning('Frontend revalidation skipped: URL or secret is not configured.');

            return;
        }

        $response = Http::asJson()
            ->withHeader('X-Revalidation-Secret', (string) $secret)
            ->timeout((int) config('api.revalidation.timeout', 5))
            ->post($url, [
                'tags' => $this->tags,
                'keys' => $this->changedKeys,
            ]);

        // Throws on 4xx/5xx so the job retries per $backoff.
        $response->throw();

        Log::info('Frontend cache revalidated.', [
            'tags' => $this->tags,
            'status' => $response->status(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        // The storefront will still self-correct when its ISR TTL lapses, so
        // this degrades rather than breaks — but it needs to be visible.
        Log::error('Frontend cache revalidation failed after all retries.', [
            'tags' => $this->tags,
            'keys' => $this->changedKeys,
            'exception' => $exception->getMessage(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['revalidation', ...$this->tags];
    }
}
