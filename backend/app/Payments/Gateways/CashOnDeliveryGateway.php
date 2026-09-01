<?php

declare(strict_types=1);

namespace App\Payments\Gateways;

use App\Models\Order;
use App\Models\Payment;
use App\Payments\Contracts\PaymentGatewayInterface;
use App\Payments\Data\PaymentIntent;
use App\Payments\Data\PaymentVerification;
use App\Payments\Data\RefundResult;
use App\Payments\Data\WebhookEvent;
use App\Payments\Exceptions\WebhookVerificationException;
use Illuminate\Http\Request;

/**
 * Cash collected by the courier on delivery.
 *
 * ## Why an offline method implements the same interface
 *
 * It would be simpler to special-case cash on delivery in the order pipeline
 * and reserve the gateway abstraction for real processors. That is the wrong
 * trade: the moment one payment method is handled by an `if` in OrderService,
 * the next one is too, and the interface stops being the single entry point
 * that makes the other gateways safe.
 *
 * Implementing it here means the core does exactly one thing for every method —
 * resolve a gateway and call it — so there is no branch to get wrong, and this
 * class is the proof that the abstraction covers a genuinely non-remote case.
 *
 * It does not extend {@see AbstractGateway}, which exists for gateways that
 * make HTTP calls. There is nothing to configure and nothing to reach.
 *
 * ## The distinction this class turns on
 *
 * **Arranged is not paid.** `initiate()` returns a *completed* intent — nothing
 * further is required of the customer, and there is no page to redirect to. But
 * `verify()` returns **pending**, and keeps returning pending until a human
 * records the collection.
 *
 * Collapsing those two would mark every cash order as paid the instant it was
 * placed, so the store's revenue figures would count money nobody had collected
 * and the unpaid-orders queue would always be empty. The order is *confirmed*
 * at placement — the store has agreed to ship before being paid — while the
 * payment stays Pending until the cash actually arrives.
 */
final class CashOnDeliveryGateway implements PaymentGatewayInterface
{
    public function identifier(): string
    {
        return 'cash_on_delivery';
    }

    public function displayName(): string
    {
        return 'Cash on delivery';
    }

    /**
     * Always available.
     *
     * Unlike a remote processor there is nothing to configure, so there is no
     * state in which this is switched on but unusable. Whether it is *offered*
     * is still a store setting, checked at checkout — this method answers
     * whether it could work at all.
     */
    public function isAvailable(): bool
    {
        return true;
    }

    public function isOffline(): bool
    {
        return true;
    }

    /**
     * Nothing to initiate.
     *
     * Returns a completed intent: the arrangement is made, no redirect exists.
     * The payment row stays Pending — see the class docblock.
     *
     * @param  array<string, mixed>  $context
     */
    public function initiate(Order $order, Payment $payment, array $context = []): PaymentIntent
    {
        return PaymentIntent::completed(
            gateway: $this->identifier(),
            // The order number is the reference a courier and an accounts clerk
            // both quote. There is no processor to issue anything better.
            reference: $order->order_number,
            raw: [
                'method' => 'cash_on_delivery',
                'note' => 'Payment is collected by the courier on delivery.',
                'arranged_at' => now()->toIso8601String(),
            ],
        );
    }

    /**
     * Unreachable in normal operation.
     *
     * There is no hosted page, so no browser ever returns from one. Kept
     * consistent with {@see verify()} rather than throwing, so that a
     * mis-routed request produces the honest answer — "still awaiting
     * collection" — instead of a 500.
     */
    public function handleCallback(Request $request, Payment $payment, string $outcome): PaymentVerification
    {
        return $this->verify($payment);
    }

    /**
     * Pending until a human says otherwise.
     *
     * There is no processor to ask. The money arrives when a courier hands it
     * over, and that fact enters the system through an admin recording it —
     * `POST /admin/orders/{order}/payment` — not through this method.
     *
     * Returning Paid here would be the exact failure the architecture exists to
     * prevent: a payment marked successful with nothing having verified it.
     */
    public function verify(Payment $payment): PaymentVerification
    {
        if ($payment->isPaid()) {
            /*
             * Already settled by an admin recording the collection. Reported
             * back as paid so a re-verification does not contradict the ledger,
             * and so this method is safe to call repeatedly.
             */
            return PaymentVerification::paid(
                gateway: $this->identifier(),
                reference: $payment->transaction_reference ?? $payment->order->order_number,
                amount: (int) $payment->amount,
                currency: $payment->currency,
                raw: ['settled_by' => 'manual_collection'],
            );
        }

        return PaymentVerification::pending(
            gateway: $this->identifier(),
            reference: $payment->transaction_reference,
            raw: ['note' => 'Awaiting collection by the courier.'],
        );
    }

    /**
     * There are no webhooks for cash.
     *
     * Throws rather than returning an ignorable event: a request claiming to be
     * a cash-on-delivery webhook is not a real notification from anywhere, and
     * quietly accepting it would leave an endpoint that anyone can post to.
     */
    public function parseWebhook(Request $request): WebhookEvent
    {
        throw WebhookVerificationException::notConfigured($this->identifier());
    }

    /**
     * Records that cash was handed back. Does not move any money.
     *
     * Returned as completed because, from the application's point of view, it
     * is: the refund happened in the physical world and this is the record of
     * it. RefundService consults {@see supportsRefunds()} first and normally
     * does not call this at all.
     */
    public function refund(Payment $payment, int $amount, ?string $reason = null): RefundResult
    {
        return RefundResult::completed(
            gateway: $this->identifier(),
            reference: 'manual-'.$payment->uuid,
            amount: $amount,
            currency: $payment->currency,
            raw: [
                'method' => 'manual',
                'note' => 'Cash refunds are handled outside the application; this records that one occurred.',
                'reason' => $reason,
            ],
        );
    }

    /**
     * No programmatic reversal exists.
     *
     * A cash refund is a person handing money back. RefundService reads this
     * and records the refund without calling a processor — asking one to
     * reverse a transaction it never had would fail, and failing would block a
     * refund that has already physically happened.
     */
    public function supportsRefunds(): bool
    {
        return false;
    }
}
