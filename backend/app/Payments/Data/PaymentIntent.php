<?php

declare(strict_types=1);

namespace App\Payments\Data;

/**
 * What a gateway returns when a payment is initiated.
 *
 * The result of "I have an order; take the customer to where they can pay."
 *
 * ## Why this is a DTO rather than an array
 *
 * Every gateway answers this question differently. SSLCommerz returns a
 * `GatewayPageURL`, Stripe a Checkout Session with a `url`, bKash a
 * `bkashURL` plus a `paymentID` that must be echoed back on execute. If
 * PaymentService received each gateway's raw array, it would have to know which
 * key to read for which gateway — and that knowledge is exactly what the
 * abstraction exists to remove.
 *
 * So each gateway maps its own response into this shape, and the core reads one
 * set of properties regardless of who is processing the money.
 *
 * ## Three kinds of outcome
 *
 * - **Redirect** ({@see $redirectUrl} set) — the common hosted-page flow.
 * - **Completed** ({@see $isCompleted}) — no redirect needed. Cash on delivery
 *   is the case that matters: the payment is "arranged" the moment the order is
 *   placed, and forcing a redirect step for it would invent a page with nothing
 *   on it.
 * - **Failed** ({@see $isFailed}) — the gateway refused before the customer
 *   ever saw a page. Distinct from a *declined* payment, which happens later.
 */
final readonly class PaymentIntent
{
    /**
     * @param  string  $gateway  Identifier of the gateway that produced this.
     * @param  string|null  $reference  The gateway's own id for this attempt, when it
     *                                  issues one up front. Stored on the payment so a later
     *                                  callback or webhook can be matched back to it.
     * @param  string|null  $redirectUrl  Where to send the customer's browser.
     * @param  array<string, mixed>  $payload  Extra data the client needs — a Stripe
     *                                         publishable key, a form to auto-submit.
     * @param  array<string, mixed>  $raw  The gateway's response, for the audit trail.
     */
    private function __construct(
        public string $gateway,
        public ?string $reference = null,
        public ?string $redirectUrl = null,
        public bool $isCompleted = false,
        public bool $isFailed = false,
        public ?string $failureReason = null,
        public array $payload = [],
        public array $raw = [],
    ) {}

    /**
     * The customer must be sent to the gateway to pay.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $raw
     */
    public static function redirect(
        string $gateway,
        string $redirectUrl,
        ?string $reference = null,
        array $payload = [],
        array $raw = [],
    ): self {
        return new self(
            gateway: $gateway,
            reference: $reference,
            redirectUrl: $redirectUrl,
            payload: $payload,
            raw: $raw,
        );
    }

    /**
     * Nothing further is required of the customer right now.
     *
     * Cash on delivery: the arrangement is complete at placement even though no
     * money has moved. Note that this does NOT mean paid — the payment status
     * is still Pending until the courier collects. See CashOnDeliveryGateway.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function completed(string $gateway, ?string $reference = null, array $raw = []): self
    {
        return new self(
            gateway: $gateway,
            reference: $reference,
            isCompleted: true,
            raw: $raw,
        );
    }

    /**
     * The gateway refused to start the payment at all.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function failed(string $gateway, string $reason, array $raw = []): self
    {
        return new self(
            gateway: $gateway,
            isFailed: true,
            failureReason: $reason,
            raw: $raw,
        );
    }

    /**
     * Whether the customer's browser needs to go somewhere.
     */
    public function requiresRedirect(): bool
    {
        return $this->redirectUrl !== null && $this->redirectUrl !== '';
    }

    /**
     * The shape the API returns to the storefront.
     *
     * `raw` is deliberately absent. It is a gateway payload kept for
     * reconciliation and dispute evidence, and its contents are not something
     * this application controls — echoing it to a browser would publish
     * whatever the processor happened to include.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'gateway' => $this->gateway,
            'reference' => $this->reference,
            'redirect_url' => $this->redirectUrl,
            'requires_redirect' => $this->requiresRedirect(),
            'is_completed' => $this->isCompleted,
            'is_failed' => $this->isFailed,
            'failure_reason' => $this->failureReason,
            'payload' => $this->payload,
        ];
    }
}
