<?php

declare(strict_types=1);

namespace App\Payments\Data;

/**
 * The authoritative outcome of a payment, established server-side.
 *
 * ## This is the only thing allowed to settle a payment
 *
 * The brief's central rule — *never mark payment as successful based only on
 * frontend response* — is enforced by making this DTO the sole input to
 * PaymentService's settlement path, and by only ever constructing it from a
 * **server-to-server call to the gateway**.
 *
 * A browser returning from a hosted page carries a query string. That query
 * string is a *hint that something happened*, nothing more: it travelled
 * through the customer's machine, where it can be edited. So the callback
 * handler does not read `status=SUCCESS` and believe it — it takes the
 * transaction id out of the request and asks the gateway directly what that
 * transaction's real state is. The answer comes back as one of these.
 *
 * Webhooks are treated with the same suspicion. A webhook is an unauthenticated
 * HTTP request from the open internet claiming an order was paid; it is
 * signature-verified first, and for gateways where the signature covers only
 * the envelope rather than the amount, verified again by transaction lookup.
 *
 * ## Amount is part of the verdict
 *
 * {@see $amount} is what the *gateway* says was captured, in minor units, and
 * PaymentService compares it against the order's own total. Without that check,
 * a callback pointing at the wrong transaction — or a deliberately substituted
 * one — could settle a large order with a small payment.
 */
final readonly class PaymentVerification
{
    /** The gateway confirms this transaction captured the money. */
    public const STATUS_PAID = 'paid';

    /** The gateway confirms this transaction did not succeed. */
    public const STATUS_FAILED = 'failed';

    /** The customer abandoned the payment at the gateway. */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Still in flight — an async method the gateway has not settled yet.
     *
     * Deliberately distinct from failed. Treating "not yet" as "no" would
     * cancel orders that are about to be paid, and a bank transfer that clears
     * overnight is an ordinary event rather than an error.
     */
    public const STATUS_PENDING = 'pending';

    /** The gateway confirms the money was returned. */
    public const STATUS_REFUNDED = 'refunded';

    /**
     * @param  string  $status  One of the STATUS_* constants.
     * @param  string|null  $reference  The gateway's transaction id.
     * @param  int|null  $amount  Minor units, as reported by the gateway.
     * @param  array<string, mixed>  $raw  The gateway's response, for the audit trail.
     */
    public function __construct(
        public string $gateway,
        public string $status,
        public ?string $reference = null,
        public ?int $amount = null,
        public ?string $currency = null,
        public ?string $failureReason = null,
        public ?string $cardBrand = null,
        public ?string $cardLastFour = null,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function paid(
        string $gateway,
        string $reference,
        int $amount,
        string $currency,
        ?string $cardBrand = null,
        ?string $cardLastFour = null,
        array $raw = [],
    ): self {
        return new self(
            gateway: $gateway,
            status: self::STATUS_PAID,
            reference: $reference,
            amount: $amount,
            currency: $currency,
            cardBrand: $cardBrand,
            cardLastFour: $cardLastFour,
            raw: $raw,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function failed(
        string $gateway,
        string $reason,
        ?string $reference = null,
        array $raw = [],
    ): self {
        return new self(
            gateway: $gateway,
            status: self::STATUS_FAILED,
            reference: $reference,
            failureReason: $reason,
            raw: $raw,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function cancelled(string $gateway, ?string $reference = null, array $raw = []): self
    {
        return new self(
            gateway: $gateway,
            status: self::STATUS_CANCELLED,
            reference: $reference,
            failureReason: 'The payment was cancelled.',
            raw: $raw,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function pending(string $gateway, ?string $reference = null, array $raw = []): self
    {
        return new self(
            gateway: $gateway,
            status: self::STATUS_PENDING,
            reference: $reference,
            raw: $raw,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function refunded(
        string $gateway,
        string $reference,
        int $amount,
        string $currency,
        array $raw = [],
    ): self {
        return new self(
            gateway: $gateway,
            status: self::STATUS_REFUNDED,
            reference: $reference,
            amount: $amount,
            currency: $currency,
            raw: $raw,
        );
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isRefunded(): bool
    {
        return $this->status === self::STATUS_REFUNDED;
    }

    /**
     * Whether this outcome is final, or worth polling again later.
     */
    public function isSettled(): bool
    {
        return ! $this->isPending();
    }

    /**
     * Whether the gateway's amount agrees with what the order expects.
     *
     * The check PaymentService runs before settling anything. A verification
     * that reports a different figure is not a payment for this order, whatever
     * else it says.
     */
    public function matchesAmount(int $expected, int $tolerance = 0): bool
    {
        // A gateway that reports no amount cannot be checked against one. The
        // caller decides whether that is acceptable — it is refused by default
        // under `require_amount_match`.
        if ($this->amount === null) {
            return false;
        }

        return abs($this->amount - $expected) <= $tolerance;
    }
}
