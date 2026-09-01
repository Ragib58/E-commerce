<?php

declare(strict_types=1);

namespace App\Payments\Data;

/**
 * What a gateway reports when asked to return money.
 *
 * Kept separate from {@see PaymentVerification} even though both describe an
 * outcome, because they answer different questions and carry different
 * consequences. A verification says "did this payment succeed"; a refund result
 * says "did this reversal succeed", and the reversal has its own gateway
 * reference that has to be stored against the refund row for reconciliation.
 *
 * ## Pending is not success
 *
 * Several processors accept a refund and settle it hours later. `isPending()`
 * exists so RefundService can record the refund as requested without marking
 * the customer repaid — telling someone their money is back when the processor
 * has only queued it produces a support call two days later.
 */
final readonly class RefundResult
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_FAILED = 'failed';

    /**
     * @param  int|null  $amount  Minor units actually reversed, as the gateway reports it.
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $gateway,
        public string $status,
        public ?string $reference = null,
        public ?int $amount = null,
        public ?string $currency = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function completed(
        string $gateway,
        string $reference,
        int $amount,
        ?string $currency = null,
        array $raw = [],
    ): self {
        return new self(
            gateway: $gateway,
            status: self::STATUS_COMPLETED,
            reference: $reference,
            amount: $amount,
            currency: $currency,
            raw: $raw,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function pending(
        string $gateway,
        ?string $reference = null,
        ?int $amount = null,
        array $raw = [],
    ): self {
        return new self(
            gateway: $gateway,
            status: self::STATUS_PENDING,
            reference: $reference,
            amount: $amount,
            raw: $raw,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function failed(string $gateway, string $reason, array $raw = []): self
    {
        return new self(
            gateway: $gateway,
            status: self::STATUS_FAILED,
            failureReason: $reason,
            raw: $raw,
        );
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Whether the gateway accepted the reversal, settled or not.
     */
    public function wasAccepted(): bool
    {
        return ! $this->isFailed();
    }
}
