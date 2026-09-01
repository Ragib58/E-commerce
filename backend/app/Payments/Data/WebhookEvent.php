<?php

declare(strict_types=1);

namespace App\Payments\Data;

use App\Payments\Exceptions\WebhookVerificationException;

/**
 * A verified, normalised webhook.
 *
 * ## An instance of this class is a claim that has already been authenticated
 *
 * A webhook arrives as an unauthenticated HTTP request from the open internet
 * asserting that an order was paid. Anyone can send one. The only thing that
 * separates a real notification from a forgery is the signature, so a gateway's
 * `parseWebhook()` verifies that signature *before* constructing this object
 * and throws {@see WebhookVerificationException} when
 * it fails.
 *
 * The consequence is a useful invariant: **if you are holding a WebhookEvent,
 * the signature checked out.** Nothing downstream re-derives that, and nothing
 * downstream is permitted to construct one from a raw request.
 *
 * ## Signature verification is still not sufficient on its own
 *
 * It proves the message came from the gateway. It does not prove the message
 * describes the order we think it does, and for some processors the signed
 * envelope does not cover the amount. So PaymentService takes the reference out
 * of the event and re-verifies by transaction lookup before settling anything.
 *
 * Two verifications sounds redundant and is not: the signature stops forgeries,
 * the lookup stops replays and mismatches.
 *
 * ## Unrecognised events
 *
 * Gateways send far more event types than a store cares about. An event this
 * application has no handling for is {@see $isIgnorable} — acknowledged with a
 * 200 so the gateway stops retrying, and otherwise ignored. Returning an error
 * for an event we simply do not need would put the endpoint into a permanent
 * retry loop and, with most processors, eventually get the webhook disabled.
 */
final readonly class WebhookEvent
{
    /** The gateway says a payment succeeded. */
    public const TYPE_PAYMENT_SUCCEEDED = 'payment.succeeded';

    /** The gateway says a payment failed. */
    public const TYPE_PAYMENT_FAILED = 'payment.failed';

    /** The customer abandoned the payment. */
    public const TYPE_PAYMENT_CANCELLED = 'payment.cancelled';

    /** A refund settled at the gateway. */
    public const TYPE_REFUND_SUCCEEDED = 'refund.succeeded';

    /** Something we do not act on. Acknowledged, then dropped. */
    public const TYPE_UNHANDLED = 'unhandled';

    /**
     * @param  string  $type  One of the TYPE_* constants.
     * @param  string|null  $reference  The gateway's transaction id, used to re-verify.
     * @param  string|null  $orderReference  Our own order number, when the gateway echoes it.
     * @param  int|null  $amount  Minor units, as claimed. Never trusted without a lookup.
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $gateway,
        public string $type,
        public ?string $reference = null,
        public ?string $orderReference = null,
        public ?int $amount = null,
        public ?string $currency = null,
        public ?string $eventId = null,
        public array $raw = [],
    ) {}

    /**
     * An event this application does not act on.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function ignorable(string $gateway, ?string $eventId = null, array $raw = []): self
    {
        return new self(
            gateway: $gateway,
            type: self::TYPE_UNHANDLED,
            eventId: $eventId,
            raw: $raw,
        );
    }

    public function isIgnorable(): bool
    {
        return $this->type === self::TYPE_UNHANDLED;
    }

    public function indicatesSuccess(): bool
    {
        return $this->type === self::TYPE_PAYMENT_SUCCEEDED;
    }

    public function indicatesFailure(): bool
    {
        return in_array($this->type, [
            self::TYPE_PAYMENT_FAILED,
            self::TYPE_PAYMENT_CANCELLED,
        ], strict: true);
    }

    public function indicatesRefund(): bool
    {
        return $this->type === self::TYPE_REFUND_SUCCEEDED;
    }
}
