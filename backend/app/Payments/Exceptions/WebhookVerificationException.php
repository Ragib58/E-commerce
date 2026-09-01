<?php

declare(strict_types=1);

namespace App\Payments\Exceptions;

use RuntimeException;

/**
 * An inbound webhook could not be authenticated.
 *
 * Its own type rather than a {@see PaymentException}, because the two demand
 * opposite responses. A payment failure is an event to record against an order;
 * an unverifiable webhook is a **request that may not be from the gateway at
 * all**, and the correct handling is to record nothing, change nothing, and
 * answer with a 400.
 *
 * Separating them stops a catch-all handler from treating a forged notification
 * as a legitimate failed payment and writing it into an order's history.
 *
 * The message is deliberately kept out of the HTTP response. Telling a caller
 * *why* their signature was rejected — wrong secret, missing header, stale
 * timestamp — is an oracle for constructing one that passes.
 */
final class WebhookVerificationException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public static function missingSignature(string $gateway): self
    {
        return new self(
            sprintf('Webhook for "%s" carried no signature header.', $gateway),
            ['gateway' => $gateway],
        );
    }

    public static function invalidSignature(string $gateway): self
    {
        return new self(
            sprintf('Webhook signature for "%s" did not match.', $gateway),
            ['gateway' => $gateway],
        );
    }

    /**
     * The gateway has no webhook secret configured.
     *
     * Treated as a verification failure rather than a configuration warning:
     * without a secret nothing can be verified, and processing the webhook
     * anyway would mean accepting unauthenticated instructions about money.
     */
    public static function notConfigured(string $gateway): self
    {
        return new self(
            sprintf('No webhook secret is configured for "%s", so webhooks cannot be verified.', $gateway),
            ['gateway' => $gateway],
        );
    }

    /**
     * The body was not in the shape the gateway documents.
     */
    public static function malformed(string $gateway, string $detail): self
    {
        return new self(
            sprintf('Malformed webhook body for "%s": %s', $gateway, $detail),
            ['gateway' => $gateway],
        );
    }
}
