<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCart;
use App\Http\Requests\Api\V1\Checkout\BillingAddressRequest;
use App\Http\Requests\Api\V1\Checkout\CustomerStepRequest;
use App\Http\Requests\Api\V1\Checkout\PaymentMethodRequest;
use App\Http\Requests\Api\V1\Checkout\ShippingAddressRequest;
use App\Http\Requests\Api\V1\Checkout\ShippingMethodRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Cart;
use App\Models\CheckoutSession;
use App\Services\CheckoutService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The seven-step checkout.
 *
 * ## Open to guests, by design
 *
 * There is no `auth:sanctum` on these routes. Guest checkout is a first-class
 * path, and the checkout *session token* is the authorization boundary — a
 * request can only ever act on the session its token resolves to, and
 * CheckoutService re-checks that a claimed session is not someone else's.
 *
 * ## Every step returns the whole checkout
 *
 * Like the cart, and for the same reason: choosing a shipping method changes the
 * total, changing the address can change which methods are available, and
 * returning only the field that changed would force the client to recompute —
 * and a client that computes totals is a client whose totals can be wrong.
 *
 * ## The token travels in a header
 *
 * `X-Checkout-Token`, matching the cart's `X-Cart-Token`. Not a cookie, for the
 * reasons in ResolveCart: the API is deliberately stateless, and an
 * automatically-attached credential would reintroduce the CSRF surface that
 * absence exists to avoid.
 */
final class CheckoutController extends Controller
{
    use ApiResponse;

    /** Header carrying the checkout session token. */
    public const HEADER = 'X-Checkout-Token';

    public function __construct(
        private readonly CheckoutService $checkout,
    ) {}

    /**
     * POST /checkout
     *
     * Begin or resume a checkout for the caller's cart.
     *
     * Idempotent: a client that calls this on every page load gets the same
     * session back rather than discarding the address already entered.
     */
    public function start(Request $request): JsonResponse
    {
        $cart = $this->requireCart($request);

        $session = $this->checkout->start(
            cart: $cart,
            user: $request->user(),
            ipAddress: $request->ip(),
        );

        return $this->successResponse(
            data: $this->checkout->summarise($session),
            message: 'Checkout started.',
            status: 201,
            // Echoed so a client that has just been given a session knows what
            // to store for the following steps.
            headers: [self::HEADER => $session->token],
        );
    }

    /**
     * GET /checkout/{token}
     *
     * The checkout as it currently stands, repriced from the catalog.
     */
    public function show(Request $request, string $token): JsonResponse
    {
        $session = $this->session($request, $token);

        return $this->successResponse(
            data: $this->checkout->summarise($session),
            message: 'Checkout retrieved.',
        );
    }

    /**
     * PUT /checkout/{token}/customer — step 1
     */
    public function setCustomer(CustomerStepRequest $request, string $token): JsonResponse
    {
        $session = $this->session($request, $token);

        $this->checkout->setCustomer($session, [
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'phone' => $request->input('phone'),
        ]);

        return $this->stepResponse($session, 'Your details have been saved.');
    }

    /**
     * PUT /checkout/{token}/shipping-address — step 2
     */
    public function setShippingAddress(ShippingAddressRequest $request, string $token): JsonResponse
    {
        $session = $this->session($request, $token);

        $this->checkout->setShippingAddress($session, $request->validated());

        return $this->stepResponse($session, 'Delivery address saved.');
    }

    /**
     * PUT /checkout/{token}/billing-address — step 3
     */
    public function setBillingAddress(BillingAddressRequest $request, string $token): JsonResponse
    {
        $session = $this->session($request, $token);

        $this->checkout->setBillingAddress(
            session: $session,
            address: $request->address(),
            sameAsShipping: $request->sameAsShipping(),
        );

        return $this->stepResponse($session, 'Billing address saved.');
    }

    /**
     * GET /checkout/{token}/shipping-methods
     *
     * The methods this order may choose between, already filtered by its
     * subtotal and destination. Offering an option and then rejecting it at the
     * next step is the checkout equivalent of a broken link.
     */
    public function shippingMethods(Request $request, string $token): JsonResponse
    {
        $session = $this->session($request, $token);

        return $this->successResponse(
            data: $this->checkout->availableShippingMethods($session),
            message: 'Delivery methods retrieved.',
        );
    }

    /**
     * PUT /checkout/{token}/shipping-method — step 4
     */
    public function setShippingMethod(ShippingMethodRequest $request, string $token): JsonResponse
    {
        $session = $this->session($request, $token);

        $this->checkout->setShippingMethod(
            $session,
            $request->string('shipping_method')->toString(),
        );

        return $this->stepResponse($session, 'Delivery method saved.');
    }

    /**
     * GET /checkout/payment-methods
     *
     * Gateway-backed methods appear marked unavailable rather than hidden: a
     * shopper who expects to pay by card should be told it is coming, not left
     * wondering whether the page failed to load.
     */
    public function paymentMethods(): JsonResponse
    {
        return $this->successResponse(
            data: $this->checkout->availablePaymentMethods(),
            message: 'Payment methods retrieved.',
        );
    }

    /**
     * PUT /checkout/{token}/payment-method — step 5
     */
    public function setPaymentMethod(PaymentMethodRequest $request, string $token): JsonResponse
    {
        $session = $this->session($request, $token);

        $this->checkout->setPaymentMethod(
            session: $session,
            method: $request->string('payment_method')->toString(),
            customerNote: $request->input('customer_note'),
        );

        return $this->stepResponse($session, 'Payment method saved.');
    }

    /**
     * POST /checkout/{token}/review — step 6
     *
     * Prices the order in full and records that the shopper saw it. Stock is
     * reserved here, at the last moment before placement — see
     * StockReservationService for why the hold is taken late.
     */
    public function review(Request $request, string $token): JsonResponse
    {
        $session = $this->session($request, $token);

        return $this->successResponse(
            data: $this->checkout->review($session),
            message: 'Review your order before placing it.',
        );
    }

    /**
     * POST /checkout/{token}/place — step 7
     *
     * The only endpoint that creates an order.
     *
     * The `Idempotency-Key` header is the duplicate-order guard. A client that
     * sends one gets exactly one order no matter how many times the request is
     * retried — a double-clicked button, a timeout, a flaky connection. Without
     * it the session check still catches the common case, but the header is
     * what makes the guarantee hold under genuine concurrency.
     */
    public function place(Request $request, string $token): JsonResponse
    {
        $session = $this->session($request, $token);

        $order = $this->checkout->place(
            session: $session,
            idempotencyKey: $this->idempotencyKey($request),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        $order->load(['items', 'addresses', 'statusHistory']);

        return $this->successResponse(
            data: new OrderResource($order),
            message: 'Your order has been placed.',
            status: 201,
        );
    }

    /**
     * DELETE /checkout/{token}
     *
     * Abandon a checkout and release the stock it was holding.
     *
     * Worth an explicit endpoint rather than waiting for expiry: a shopper who
     * clicks "back to cart" has told us they are not buying right now, and
     * holding the last unit for another fifteen minutes denies it to someone
     * who is.
     */
    public function abandon(Request $request, string $token): JsonResponse
    {
        $session = $this->session($request, $token);

        $this->checkout->abandon($session);

        return $this->successResponse(
            message: 'Checkout cancelled.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve the session named in the URL, for this caller.
     *
     * Ownership is re-checked inside CheckoutService on every call rather than
     * once at the start: a session claimed by an account must not be reachable
     * by a guest holding a leaked token, and checking once would leave the
     * later steps unguarded.
     */
    private function session(Request $request, string $token): CheckoutSession
    {
        return $this->checkout->resolve($token, $request->user());
    }

    /**
     * The whole recomputed checkout, after a step has been saved.
     */
    private function stepResponse(CheckoutSession $session, string $message): JsonResponse
    {
        return $this->successResponse(
            data: $this->checkout->summarise($session->refresh()),
            message: $message,
        );
    }

    /**
     * The client's idempotency key, validated for shape.
     *
     * A bounded, opaque string. Checked here rather than trusted because it
     * reaches a uniquely-indexed column: an unbounded value would be truncated
     * by the database, and two distinct long keys sharing a prefix would then
     * collide and silently suppress a legitimate second order.
     */
    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '') {
            return null;
        }

        return preg_match('/^[A-Za-z0-9._\-]{8,64}$/', $key) === 1 ? $key : null;
    }

    /**
     * The cart attached by the ResolveCart middleware.
     */
    private function requireCart(Request $request): Cart
    {
        $cart = $request->attributes->get(ResolveCart::ATTRIBUTE);

        if (! $cart instanceof Cart) {
            abort(422, 'Your cart is empty.');
        }

        return $cart;
    }
}
