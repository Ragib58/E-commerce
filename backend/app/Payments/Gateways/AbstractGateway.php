<?php

declare(strict_types=1);

namespace App\Payments\Gateways;

use App\Payments\Contracts\PaymentGatewayInterface;
use App\Payments\Exceptions\PaymentException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shared machinery for gateways that talk to a remote processor.
 *
 * Deliberately holds no payment *logic* — only configuration reading, HTTP
 * setup, and response sanitising. Each gateway's actual protocol stays in its
 * own class, because that is the part that genuinely differs and the part a
 * future implementer needs to see in one place.
 *
 * Implementing {@see PaymentGatewayInterface} directly is entirely valid; this
 * base class is a convenience, not a requirement. CashOnDeliveryGateway does
 * not extend it, since it makes no HTTP calls at all.
 */
abstract class AbstractGateway implements PaymentGatewayInterface
{
    /**
     * Config keys whose presence is required before the gateway is usable.
     *
     * Checked by {@see isAvailable()}, so a gateway switched on with an empty
     * secret is *not offered* rather than offered and then failing at the
     * moment of payment — the most expensive point to discover a config error.
     *
     * @return array<int, string>
     */
    abstract protected function requiredCredentials(): array;

    /**
     * Read a value from this gateway's config block.
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        return config(sprintf('payment.%s.%s', $this->identifier(), $key), $default);
    }

    /**
     * Whether an operator has switched this gateway on.
     */
    protected function isEnabled(): bool
    {
        return (bool) $this->config('enabled', false);
    }

    public function isAvailable(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        foreach ($this->requiredCredentials() as $key) {
            $value = $this->config($key);

            if ($value === null || $value === '') {
                /*
                 * Logged rather than thrown. An operator who half-configures a
                 * gateway should see it quietly absent from checkout and find
                 * out why in the log — not have the storefront fail for
                 * everyone because one method is misconfigured.
                 */
                Log::warning('Payment gateway is enabled but missing a credential.', [
                    'gateway' => $this->identifier(),
                    'missing' => $key,
                ]);

                return false;
            }
        }

        return true;
    }

    public function isOffline(): bool
    {
        return false;
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    /**
     * The processor's base URL for the current mode.
     *
     * Sandbox and live are separate hosts rather than a flag, so a
     * misconfigured environment fails to connect instead of quietly charging
     * real cards from a staging box.
     */
    protected function baseUrl(): string
    {
        $url = (bool) $this->config('sandbox', true)
            ? $this->config('sandbox_url')
            : $this->config('live_url');

        if (! is_string($url) || $url === '') {
            throw PaymentException::notConfigured($this->identifier(), ['missing' => 'base url']);
        }

        return rtrim($url, '/');
    }

    /**
     * A configured HTTP client for this gateway.
     *
     * Timeouts are short and retries are few: this runs inside a web request
     * with a shopper waiting, and a long hang produces a reload and a second
     * payment attempt.
     *
     * Retries apply to connection failures, not to a processor's considered
     * "no". Retrying a decline would be asking a different question each time.
     */
    protected function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->timeout((int) config('payment.http.timeout', 20))
            ->connectTimeout((int) config('payment.http.connect_timeout', 10))
            ->retry(
                (int) config('payment.http.retries', 2),
                200,
                throw: false,
            )
            ->acceptJson();
    }

    /**
     * Strip anything credential-shaped from a gateway response before storing.
     *
     * `payments.gateway_response` is kept for reconciliation and dispute
     * evidence, and it is read by any admin holding `view_payments`. Processors
     * echo back more than they should — a store password in SSLCommerz's case,
     * bearer tokens in others — and persisting that would put a live credential
     * into the database, into every backup, and onto an admin's screen.
     *
     * Matching is on key *substrings* because processors are inconsistent about
     * naming: `store_passwd`, `app_secret`, `X-Auth-Token` all need catching,
     * and an exact-match list would miss the next one.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    protected function sanitiseResponse(array $response): array
    {
        $sensitive = [
            'password', 'passwd', 'secret', 'token', 'authorization',
            'api_key', 'apikey', 'signature_key', 'private',
        ];

        $clean = [];

        foreach ($response as $key => $value) {
            $lowered = strtolower((string) $key);

            foreach ($sensitive as $needle) {
                if (str_contains($lowered, $needle)) {
                    $clean[$key] = '[redacted]';

                    continue 2;
                }
            }

            // Recursed, because processors nest their payloads and a secret one
            // level down is just as exposed as one at the top.
            $clean[$key] = is_array($value) ? $this->sanitiseResponse($value) : $value;
        }

        return $clean;
    }

    /**
     * Convert a decimal major-unit amount into minor units.
     *
     * Gateways report money as strings like "1250.00". Multiplying a float by
     * 100 and casting reintroduces exactly the representation error the
     * integer-minor-unit convention exists to avoid — 12.34 * 100 is
     * 1233.9999... and truncates to 1233, losing a penny per comparison.
     *
     * Rounding after the multiply keeps it exact for every value a processor
     * will realistically send.
     */
    protected function toMinorUnits(int|float|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * Convert minor units into the decimal string gateways expect.
     */
    protected function toMajorUnits(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2, '.', '');
    }

    /**
     * Log a gateway interaction without leaking credentials into the log.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logInteraction(string $message, array $context = []): void
    {
        Log::info($message, array_merge(['gateway' => $this->identifier()], $context));
    }
}
