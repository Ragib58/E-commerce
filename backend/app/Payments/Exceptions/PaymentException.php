<?php

declare(strict_types=1);

namespace App\Payments\Exceptions;

use RuntimeException;

/**
 * A payment operation could not be completed.
 *
 * Carries two messages on purpose. {@see getMessage()} is for the log and holds
 * whatever the processor said; {@see customerMessage()} is what may be shown to
 * a shopper.
 *
 * The split matters because gateway errors are frequently unfit for display —
 * they name internal endpoints, quote credential-shaped identifiers, and use
 * vocabulary ("PSP declined: risk score 87") that tells a customer nothing
 * while telling an attacker something. A single message field means either the
 * log loses detail or the customer sees it, and the second is the one that
 * causes harm.
 */
class PaymentException extends RuntimeException
{
    /**
     * @param  string  $message  Diagnostic detail, for logs.
     * @param  string|null  $customerMessage  Safe to display. Falls back to a generic line.
     * @param  array<string, mixed>  $context  Structured data for the log entry.
     */
    public function __construct(
        string $message,
        private readonly ?string $customerMessage = null,
        private readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * A message that may safely be shown to a customer.
     */
    public function customerMessage(): string
    {
        return $this->customerMessage
            ?? 'We could not process your payment. Please try again or choose another method.';
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * The gateway is not configured well enough to be used.
     *
     * @param  array<string, mixed>  $context
     */
    public static function notConfigured(string $gateway, array $context = []): self
    {
        return new self(
            message: sprintf('Payment gateway "%s" is enabled but its credentials are incomplete.', $gateway),
            customerMessage: 'This payment method is temporarily unavailable. Please choose another.',
            context: array_merge(['gateway' => $gateway], $context),
        );
    }

    /**
     * The gateway could not be reached, or answered with something unusable.
     *
     * @param  array<string, mixed>  $context
     */
    public static function communicationFailed(string $gateway, string $detail, array $context = []): self
    {
        return new self(
            message: sprintf('Could not reach payment gateway "%s": %s', $gateway, $detail),
            customerMessage: 'We could not reach the payment provider. Please try again in a moment.',
            context: array_merge(['gateway' => $gateway], $context),
        );
    }

    /**
     * The gateway refused the operation.
     *
     * @param  array<string, mixed>  $context
     */
    public static function rejected(string $gateway, string $detail, ?string $customerMessage = null, array $context = []): self
    {
        return new self(
            message: sprintf('Payment gateway "%s" rejected the request: %s', $gateway, $detail),
            customerMessage: $customerMessage,
            context: array_merge(['gateway' => $gateway], $context),
        );
    }
}
