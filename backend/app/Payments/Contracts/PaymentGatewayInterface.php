<?php

declare(strict_types=1);

namespace App\Payments\Contracts;

use App\Models\Order;
use App\Models\Payment;
use App\Payments\Data\PaymentIntent;
use App\Payments\Data\PaymentVerification;
use App\Payments\Data\RefundResult;
use App\Payments\Data\WebhookEvent;
use App\Payments\Exceptions\PaymentException;
use App\Payments\Exceptions\WebhookVerificationException;
use Illuminate\Http\Request;

/**
 * What every payment gateway must be able to do.
 *
 * ## The point of this interface
 *
 * The brief asks that future gateways be addable *without changing core order
 * logic*. That property does not come from having an interface — it comes from
 * the core depending on **only** this interface and never on a concrete class.
 *
 * Concretely: `OrderService`, `PaymentService`, `CheckoutService`, and the
 * controllers contain no reference to SSLCommerz, bKash, or Stripe. The single
 * place those names appear is `config/payment.php`. Adding a fifth processor is
 * a new class here plus a line there.
 *
 * ## Why these seven methods and not others
 *
 * They are the operations the *core* needs, expressed so that no caller has to
 * know which gateway answered. Each returns a DTO rather than the processor's
 * own array, because a caller that reads `$response['GatewayPageURL']` has
 * learned it is talking to SSLCommerz — and the abstraction has leaked.
 *
 * Anything a gateway needs beyond this is its own business: bKash's grant token
 * dance and Stripe's signature scheme live inside their implementations and are
 * invisible from outside.
 *
 * ## The rule the contract is shaped around
 *
 * **A payment is settled only by {@see verify()}.**
 *
 * `verify()` is defined to make a server-to-server call to the processor. It is
 * the only method whose result may mark money as received, and it is what
 * `handleCallback()` and the webhook path both funnel into. A browser's return
 * from a hosted page and an inbound webhook are *triggers to go and check*, not
 * evidence. That is what makes "never mark payment successful based only on
 * frontend response" a structural property rather than a discipline someone has
 * to remember.
 */
interface PaymentGatewayInterface
{
    /**
     * The stable identifier for this gateway.
     *
     * Stored in `payments.gateway` and used as the config key and route
     * segment. It must never change once orders reference it — historical rows
     * carry the value, and renaming orphans them.
     */
    public function identifier(): string;

    /**
     * The name a shopper sees at checkout.
     */
    public function displayName(): string;

    /**
     * Whether this gateway is configured and usable right now.
     *
     * Must check that credentials are actually present, not merely that a flag
     * is set. A gateway enabled with an empty secret key would be offered at
     * checkout and then fail at the moment of payment — which is the most
     * expensive point at which to discover a configuration error.
     */
    public function isAvailable(): bool;

    /**
     * Whether money moves outside the application.
     *
     * True for cash on delivery: there is nothing to redirect to and nothing to
     * verify against a processor. PaymentService uses this to decide whether an
     * order may be confirmed at placement.
     */
    public function isOffline(): bool;

    /**
     * Begin a payment.
     *
     * Called after the order and its pending payment row already exist, so an
     * implementation always has a real order to reference and a payment row to
     * attach its transaction id to.
     *
     * Implementations must not mutate the order or the payment — persistence is
     * PaymentService's job. Return what happened and let the caller record it;
     * a gateway that writes to the database directly is one that can leave a
     * payment settled by a code path with no audit row.
     *
     * @param  array<string, mixed>  $context  Return URLs and other per-request data.
     *
     * @throws PaymentException on an unrecoverable error.
     */
    public function initiate(Order $order, Payment $payment, array $context = []): PaymentIntent;

    /**
     * Interpret a customer's return from the gateway.
     *
     * The request here came through the customer's browser and is therefore
     * **untrusted**. An implementation must use it only to work out *which
     * transaction* is being reported, then establish the actual status by
     * calling {@see verify()}. Reading a status field out of the request and
     * returning it would be exactly the failure this architecture exists to
     * prevent.
     *
     * The `$outcome` argument is the route the customer came back on —
     * `success`, `failure`, or `cancel`. It is a hint about which page to
     * render, not a verdict: a shopper who lands on the success URL may still
     * have an unpaid order, and this method must report what the gateway says
     * rather than what the URL implies.
     */
    public function handleCallback(Request $request, Payment $payment, string $outcome): PaymentVerification;

    /**
     * Ask the gateway what actually happened to a transaction.
     *
     * **The only authoritative source of payment status in the system.** A
     * server-to-server call, using credentials the customer does not hold,
     * against an identifier the gateway issued.
     *
     * Must be safe to call repeatedly. Duplicate callbacks, webhook retries,
     * and an admin clicking "re-check" all land here, and a gateway that
     * charged or mutated state on a status lookup would turn a retry into a
     * second transaction.
     */
    public function verify(Payment $payment): PaymentVerification;

    /**
     * Verify an inbound webhook's signature and normalise it.
     *
     * Signature verification happens *here*, before a {@see WebhookEvent} is
     * constructed — so downstream code can treat any event it receives as
     * authenticated. An unverifiable request must throw rather than return an
     * ignorable event: silently accepting an unsigned webhook would leave the
     * endpoint open to anyone who guesses the URL.
     *
     * @throws WebhookVerificationException when the signature is absent or wrong.
     */
    public function parseWebhook(Request $request): WebhookEvent;

    /**
     * Return money through the gateway.
     *
     * @param  int  $amount  Minor units. May be less than the original payment.
     *
     * @throws PaymentException when the gateway rejects it.
     */
    public function refund(Payment $payment, int $amount, ?string $reason = null): RefundResult;

    /**
     * Whether this gateway can reverse a payment programmatically.
     *
     * False for cash on delivery, where a refund is a human handing money back
     * and the application only records that it happened. RefundService reads
     * this to decide whether to call {@see refund()} at all — calling it on an
     * offline gateway would be asking a processor to reverse a transaction it
     * never had.
     */
    public function supportsRefunds(): bool;
}
