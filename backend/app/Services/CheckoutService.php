<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CheckoutStep;
use App\Enums\PaymentMethod;
use App\Models\Cart;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\User;
use App\Payments\Contracts\PaymentGatewayInterface;
use App\Payments\PaymentGatewayManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The seven-step checkout.
 *
 * ## The server owns the sequence
 *
 * The usual implementation keeps the current step in the client and posts
 * everything at the end. That makes step order a frontend concern, and a
 * frontend concern is not a constraint: a crafted request posts straight to
 * "place order" having never chosen a shipping method, and the order is created
 * with a null shipping cost.
 *
 * Here each step's answer is persisted as it is given, and
 * {@see assertStepAvailable()} refuses a step whose prerequisites are not
 * satisfied. Skipping is not a state the system can enter rather than one it
 * detects afterwards.
 *
 * ## Nothing priced is stored
 *
 * The session holds *choices* — an address, a shipping method id, a payment
 * method. Every figure is recomputed from the catalog and the method's row on
 * each read, exactly as the cart does. A total persisted at step four and
 * trusted at step seven is a three-step window in which the catalog can move,
 * and a writable surface a crafted request can aim at.
 *
 * ## Guest and registered are one path
 *
 * Both produce a `checkout_sessions` row; the only difference is whether
 * `user_id` is set. Two separate flows would mean two places where the address
 * is validated, two places where shipping is priced, and two chances for them
 * to disagree — and the guest path, being the less-tested one, is where the
 * disagreement would live.
 *
 * ## Changing an answer invalidates what depended on it
 *
 * Editing the shipping address after choosing a method clears the method: the
 * new country may not be served, and carrying the choice forward would price
 * the order with a method that is no longer offered. Any change to a priced
 * input also clears the review acknowledgement, because "you agreed to this
 * total" is only true of a total that was actually shown.
 */
final class CheckoutService
{
    /**
     * How long an idle checkout stays resumable.
     *
     * Longer than a stock reservation on purpose. The session holds no
     * inventory, so keeping it costs nothing but a row — and a shopper who
     * closes the tab to find their card should get their address back an hour
     * later, not a blank form.
     */
    private const SESSION_HOURS = 24;

    public function __construct(
        private readonly CartService $carts,
        private readonly StockReservationService $reservations,
        private readonly OrderService $orders,
        private readonly SettingsService $settings,
        private readonly PaymentGatewayManager $gateways,
        private readonly ShippingZoneService $shippingZones,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Session lifecycle
    |--------------------------------------------------------------------------
    */

    /**
     * Begin or resume a checkout for a cart.
     *
     * Idempotent: a client that calls it on every page load gets the same
     * session back rather than starting over and losing the address already
     * entered.
     *
     * @throws ValidationException
     */
    public function start(Cart $cart, ?User $user = null, ?string $ipAddress = null): CheckoutSession
    {
        $summary = $this->carts->summarise($cart);

        if ($summary['items'] === []) {
            throw ValidationException::withMessages([
                'cart' => ['Your cart is empty.'],
            ]);
        }

        $existing = CheckoutSession::query()
            ->usable()
            ->where('cart_id', $cart->getKey())
            ->latest('id')
            ->first();

        if ($existing !== null) {
            /*
             * A guest who signs in mid-checkout keeps their session and the
             * details already typed into it. Starting a fresh one would be
             * correct-looking and hostile: signing in to use a saved card is a
             * normal thing to do at step five, and losing the address entered
             * at step two for it is how a checkout gets abandoned.
             */
            if ($user !== null && $existing->user_id === null) {
                $existing->forceFill(['user_id' => $user->getKey()])->save();
            }

            $this->extend($existing);

            return $existing;
        }

        $session = CheckoutSession::query()->create([
            'token' => $this->generateToken(),
            'cart_id' => $cart->getKey(),
            'user_id' => $user?->getKey(),
            'data' => $this->initialData($user),
            'current_step' => CheckoutStep::Customer->value,
            'expires_at' => Carbon::now()->addHours(self::SESSION_HOURS),
            'ip_address' => $ipAddress,
        ]);

        return $session;
    }

    /**
     * Pre-fill what is already known about a signed-in customer.
     *
     * A registered shopper should not retype their own name and email. The
     * details are still editable — an order going to a different recipient is
     * ordinary — and whatever survives editing is what gets captured onto the
     * order.
     *
     * @return array<string, mixed>
     */
    private function initialData(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return [
            'customer' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
        ];
    }

    /**
     * Resolve a session by its token, for this cart and caller.
     *
     * The token identifies the session, but ownership is re-checked: a signed-in
     * customer may only resume their own or an unclaimed guest session, never
     * one belonging to another account. Otherwise a leaked token would expose
     * the address and phone number in it.
     *
     * @throws ValidationException
     */
    public function resolve(string $token, ?User $user = null): CheckoutSession
    {
        $session = CheckoutSession::query()->where('token', $token)->first();

        if ($session === null) {
            throw ValidationException::withMessages([
                'checkout' => ['That checkout could not be found.'],
            ]);
        }

        if ($session->user_id !== null
            && ($user === null || (int) $session->user_id !== (int) $user->getKey())) {
            // Deliberately the same message as "not found": distinguishing them
            // would confirm that a token is valid but belongs to someone else.
            throw ValidationException::withMessages([
                'checkout' => ['That checkout could not be found.'],
            ]);
        }

        /*
         * A *completed* session resolves successfully.
         *
         * It is not usable for further steps, but it is a real session with a
         * real order behind it — and the retry path depends on reaching it.
         * A client whose "place order" response was lost retries, and must be
         * handed the order it already placed; rejecting the session here as
         * expired would answer that retry with "start again" and invite the
         * shopper to order twice.
         *
         * The step guards still refuse to *mutate* it — assertStepAvailable
         * checks isUsable() — and placeFromSession returns the existing order
         * rather than creating a second. So the permissiveness is confined to
         * resolution, which is the only place it is safe.
         */
        if ($session->isExpired() && ! $session->isCompleted()) {
            throw ValidationException::withMessages([
                'checkout' => ['This checkout has expired. Please start again.'],
            ]);
        }

        return $session;
    }

    /**
     * Push the session's expiry back.
     */
    public function extend(CheckoutSession $session): void
    {
        $session->forceFill([
            'expires_at' => Carbon::now()->addHours(self::SESSION_HOURS),
        ])->save();
    }

    /*
    |--------------------------------------------------------------------------
    | Steps
    |--------------------------------------------------------------------------
    */

    /**
     * Step 1 — customer details.
     *
     * @param  array{name: string, email: string, phone?: string|null}  $details
     */
    public function setCustomer(CheckoutSession $session, array $details): CheckoutSession
    {
        $this->assertStepAvailable($session, CheckoutStep::Customer);

        $session->put([
            'customer' => [
                'name' => trim($details['name']),
                'email' => strtolower(trim($details['email'])),
                'phone' => isset($details['phone']) ? trim((string) $details['phone']) : null,
            ],
        ]);

        return $this->save($session, CheckoutStep::Customer);
    }

    /**
     * Step 2 — where the goods go.
     *
     * @param  array<string, mixed>  $address
     */
    public function setShippingAddress(CheckoutSession $session, array $address): CheckoutSession
    {
        $this->assertStepAvailable($session, CheckoutStep::ShippingAddress);

        $previousCountry = $session->get('shipping_address.country');

        $session->put(['shipping_address' => $this->normaliseAddress($address)]);

        /*
         * A change of country invalidates the shipping method.
         *
         * Not merely a change of address: moving house within one country does
         * not change which carriers serve it, and clearing a valid choice for
         * that would send the shopper back a step for nothing. Crossing a
         * border does, because the method may not be offered there at all.
         */
        if ($previousCountry !== null
            && strtoupper((string) $previousCountry) !== strtoupper((string) ($address['country'] ?? ''))) {
            $session->invalidateFrom(CheckoutStep::ShippingMethod);
        }

        // Shipping cost can depend on the destination, so the total the shopper
        // reviewed may no longer be the one they would be charged.
        $session->invalidateReview();

        return $this->save($session, CheckoutStep::ShippingAddress);
    }

    /**
     * Step 3 — where the invoice goes.
     *
     * `$sameAsShipping` is an explicit choice rather than an inferred one. Most
     * shoppers use one address and retyping it is the friction that loses
     * checkouts — but the flag has to be *set*, or a skipped step and a
     * deliberate choice would be indistinguishable.
     *
     * @param  array<string, mixed>|null  $address
     */
    public function setBillingAddress(
        CheckoutSession $session,
        ?array $address,
        bool $sameAsShipping = false,
    ): CheckoutSession {
        $this->assertStepAvailable($session, CheckoutStep::BillingAddress);

        if ($sameAsShipping) {
            $session->put([
                'billing_same_as_shipping' => true,
                'billing_address' => null,
            ]);
        } else {
            if ($address === null) {
                throw ValidationException::withMessages([
                    'billing_address' => ['Provide a billing address, or confirm it is the same as your delivery address.'],
                ]);
            }

            $session->put([
                'billing_same_as_shipping' => false,
                'billing_address' => $this->normaliseAddress($address),
            ]);
        }

        $session->invalidateReview();

        return $this->save($session, CheckoutStep::BillingAddress);
    }

    /**
     * Step 4 — the delivery method.
     *
     * Takes an id and looks up the rate. The client never sends a shipping
     * cost, for the same reason it never sends a product price.
     *
     * @throws ValidationException
     */
    public function setShippingMethod(CheckoutSession $session, string $methodUuid): CheckoutSession
    {
        $this->assertStepAvailable($session, CheckoutStep::ShippingMethod);

        $method = ShippingMethod::query()->where('uuid', $methodUuid)->first();

        if ($method === null) {
            throw ValidationException::withMessages([
                'shipping_method' => ['That delivery method could not be found.'],
            ]);
        }

        $cart = $session->cart()->firstOrFail();
        $summary = $this->carts->summarise($cart);
        $subtotal = (int) $summary['totals']['subtotal'];
        $zone = $this->resolveZoneFromSession($session);

        /*
         * Availability re-checked against this order's actual subtotal,
         * destination, and resolved zone — not merely against `is_active`. A
         * method the shopper could not have been offered must not become
         * selectable by posting its id directly.
         */
        if (! $method->isAvailableFor($subtotal, $session->get('shipping_address.country'))
            || ! $this->shippingZones->isAvailableInZone($method, $subtotal, $zone)) {
            throw ValidationException::withMessages([
                'shipping_method' => ['That delivery method is not available for your order.'],
            ]);
        }

        $session->put(['shipping_method_id' => $method->getKey()]);
        $session->invalidateReview();

        return $this->save($session, CheckoutStep::ShippingMethod);
    }

    /**
     * Step 5 — how they intend to pay.
     *
     * @throws ValidationException
     */
    public function setPaymentMethod(
        CheckoutSession $session,
        string $method,
        ?string $customerNote = null,
    ): CheckoutSession {
        $this->assertStepAvailable($session, CheckoutStep::PaymentMethod);

        $resolved = PaymentMethod::tryFrom($method);

        if ($resolved === null || ! $resolved->isEnabled($this->settings)) {
            throw ValidationException::withMessages([
                'payment_method' => ['That payment method is not available.'],
            ]);
        }

        /*
         * The method must map to a gateway that is actually configured.
         *
         * Checked here rather than only at placement, and refused loudly. A
         * method whose credentials are absent would otherwise be accepted at
         * step five and fail at step seven — after the shopper has reviewed
         * their order, which is the most expensive moment to discover a
         * configuration error.
         *
         * The check asks the gateway itself rather than consulting a hardcoded
         * list, so switching a processor on is a credential in the environment
         * and nothing else.
         */
        $gateway = $this->gatewayFor($resolved);

        if ($gateway === null || ! $gateway->isAvailable()) {
            throw ValidationException::withMessages([
                'payment_method' => ['That payment method is not available right now. Please choose another.'],
            ]);
        }

        $session->put([
            'payment_method' => $resolved->value,
            'customer_note' => $customerNote !== null && trim($customerNote) !== ''
                ? trim($customerNote)
                : null,
        ]);

        $session->invalidateReview();

        return $this->save($session, CheckoutStep::PaymentMethod);
    }

    /**
     * Step 6 — the priced order, and the shopper's acknowledgement of it.
     *
     * Reserving stock happens here, at the last moment before placement. Late
     * on purpose: a hold taken at step one would be held through however long a
     * shopper spends filling in forms, and holding inventory for an abandoned
     * checkout is the failure the cart deliberately avoids.
     *
     * @return array<string, mixed> The full review payload.
     *
     * @throws ValidationException
     */
    public function review(CheckoutSession $session): array
    {
        $this->assertStepAvailable($session, CheckoutStep::Review);

        $review = $this->summarise($session);

        if ($review['has_issues']) {
            throw ValidationException::withMessages([
                'cart' => ['Some items in your order are no longer available. Please review your cart.'],
            ]);
        }

        // Reserved inside the review, so the figures the shopper is about to
        // agree to are backed by stock actually held for them.
        $this->reservations->reserveForSession($session);

        $session->put(['reviewed_at' => Carbon::now()->toIso8601String()]);
        $this->save($session, CheckoutStep::Review);

        $review['reservation_expires_at'] = $this->reservations->expiresAtFor($session)?->toIso8601String();

        return $review;
    }

    /**
     * Step 7 — place the order.
     *
     * Delegates to OrderService, which owns the transaction, the idempotency
     * guarantee, and the stock locking. This method's job is only to confirm
     * that the session is entitled to place at all.
     *
     * @throws ValidationException
     */
    public function place(
        CheckoutSession $session,
        ?string $idempotencyKey = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Order {
        /*
         * A session that has already produced an order short-circuits to it,
         * before the step guard runs.
         *
         * The guard would refuse a completed session — correctly, since it
         * cannot be mutated further — but a retry after a lost response is not
         * an attempt to place a second order. It must be answered with the
         * first one, and refusing it here would leave the shopper looking at
         * an error for an order that exists and will be shipped.
         */
        if ($session->order_id === null) {
            $this->assertStepAvailable($session, CheckoutStep::Place);
        }

        return $this->orders->placeFromSession(
            session: $session,
            idempotencyKey: $idempotencyKey,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * The checkout as the storefront should render it.
     *
     * Every figure is computed here from the catalog and the chosen shipping
     * method. Nothing is read back from the session, which stores no money at
     * all.
     *
     * @return array<string, mixed>
     */
    public function summarise(CheckoutSession $session): array
    {
        $cart = $session->cart()->firstOrFail();
        $user = $session->user_id !== null ? User::query()->find($session->user_id) : null;
        $email = (string) ($session->get('customer.email') ?? $user?->email ?? '');

        // Coupon-aware: a stored code's discount is folded in here exactly as
        // it will be at placement, so the review step and the order agree.
        $summary = $this->carts->summarise($cart, $user, $email);

        $shippingMethod = $this->currentShippingMethod($session);
        $zone = $this->resolveZoneFromSession($session);
        $totals = $this->orders->calculateTotals($summary, $shippingMethod, $zone);

        $couponDiscount = (int) ($summary['coupon']['discount'] ?? 0);
        $totals['discount'] = $couponDiscount;
        $totals['total'] = max(0, $totals['total'] - $couponDiscount);

        if (($summary['coupon']['free_shipping'] ?? false) === true) {
            $totals['total'] = max(0, $totals['total'] - $totals['shipping']);
            $totals['shipping'] = 0;
        }

        $shipping = $session->get('shipping_address');
        $billing = $session->get('billing_address');

        if ($billing === null && $session->get('billing_same_as_shipping') === true) {
            $billing = $shipping;
        }

        return [
            'token' => $session->token,
            'current_step' => $session->nextStep()->value,
            'progress' => $session->progress(),
            'is_guest' => $session->user_id === null,

            'customer' => $session->get('customer'),
            'shipping_address' => $shipping,
            'billing_address' => $billing,
            'billing_same_as_shipping' => (bool) $session->get('billing_same_as_shipping', false),
            'customer_note' => $session->get('customer_note'),

            'shipping_method' => $shippingMethod === null ? null : [
                'id' => $shippingMethod->uuid,
                'name' => $shippingMethod->name,
                'description' => $shippingMethod->description,
                // Already the zone-priced figure — see $totals['shipping']
                // above, computed by the same ShippingZoneService::quote call.
                'rate' => $totals['shipping'],
                'estimate' => $shippingMethod->estimateLabel(),
            ],

            'payment_method' => $session->get('payment_method'),

            'items' => $summary['items'],
            'item_count' => $summary['item_count'],

            'totals' => [
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'tax' => $totals['tax'],
                'shipping' => $totals['shipping'],
                'total' => $totals['total'],
            ],

            'currency' => (string) $this->settings->get('business.currency', 'USD'),
            'has_issues' => (bool) $summary['has_issues'],
            'is_ready_to_place' => $session->isReadyToPlace(),
            'expires_at' => $session->expires_at?->toIso8601String(),
        ];
    }

    /**
     * The delivery methods this order may choose between.
     *
     * Filtered by the order's subtotal and destination *before* being offered.
     * Showing an option and rejecting it at the next step is the checkout
     * equivalent of a broken link.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableShippingMethods(CheckoutSession $session): array
    {
        $cart = $session->cart()->firstOrFail();
        $subtotal = (int) $this->carts->summarise($cart)['totals']['subtotal'];
        $country = $session->get('shipping_address.country');
        $zone = $this->resolveZoneFromSession($session);

        return ShippingMethod::query()
            ->active()
            ->ordered()
            ->get()
            ->filter(fn (ShippingMethod $method): bool => $method->isAvailableFor($subtotal, $country)
                && $this->shippingZones->isAvailableInZone($method, $subtotal, $zone))
            ->map(function (ShippingMethod $method) use ($subtotal, $zone): array {
                $quote = $this->shippingZones->quote($method, $subtotal, $zone);

                return [
                    'id' => $method->uuid,
                    'name' => $method->name,
                    'description' => $method->description,
                    'rate' => $quote['amount'],
                    'list_rate' => (int) $method->rate,
                    'is_free' => $quote['amount'] === 0,
                    'zone' => $zone?->name,
                    'estimate' => $method->estimateLabel(),
                    'estimated_from' => $quote['min_days'] !== null
                        ? now()->addWeekdays($quote['min_days'])->toDateString()
                        : null,
                    'estimated_to' => $quote['max_days'] !== null
                        ? now()->addWeekdays($quote['max_days'])->toDateString()
                        : null,
                    'requires_address' => (bool) $method->requires_address,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The payment methods currently offered.
     *
     * Gateway-backed methods are marked unavailable with a reason rather than
     * hidden. A shopper who expects to pay by card should be told it is coming,
     * not left wondering whether the page failed to load.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availablePaymentMethods(): array
    {
        return array_map(
            function (PaymentMethod $method): array {
                $gateway = $this->gatewayFor($method);
                $isAvailable = $gateway !== null && $gateway->isAvailable();

                return [
                    'value' => $method->value,
                    'label' => $method->label(),
                    'description' => $method->description(),
                    'is_available' => $isAvailable,

                    /*
                     * Named rather than hidden when unavailable. A shopper who
                     * expects to pay by card should be told it is temporarily
                     * off, not left wondering whether the page failed to load.
                     *
                     * The reason is deliberately vague: "not configured" would
                     * tell an attacker which processors this store uses and
                     * which of them are currently misconfigured.
                     */
                    'unavailable_reason' => $isAvailable
                        ? null
                        : 'This payment method is temporarily unavailable.',

                    'gateway' => $gateway?->identifier(),
                ];
            },
            PaymentMethod::enabledFor($this->settings),
        );
    }

    /**
     * The gateway that processes a given checkout method.
     *
     * Returns null when the mapping names a gateway that is not registered —
     * a misconfiguration, and one that must render the method unavailable
     * rather than throwing. A single bad config value should remove one
     * payment option, not break the checkout page for everyone.
     */
    private function gatewayFor(PaymentMethod $method): ?PaymentGatewayInterface
    {
        $identifier = app(PaymentService::class)->gatewayForMethod($method->value);

        if (! $this->gateways->has($identifier)) {
            return null;
        }

        return $this->gateways->gateway($identifier);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Refuse a step whose prerequisites are not met.
     *
     * The guard that makes the sequence a constraint rather than a suggestion.
     * The error names the step that must be completed first, so a client can
     * navigate there rather than only knowing that something was wrong.
     *
     * @throws ValidationException
     */
    private function assertStepAvailable(CheckoutSession $session, CheckoutStep $step): void
    {
        if (! $session->isUsable()) {
            throw ValidationException::withMessages([
                'checkout' => ['This checkout has expired. Please start again.'],
            ]);
        }

        $data = $session->data ?? [];

        foreach ($step->prerequisites() as $prerequisite) {
            if ($prerequisite->isSatisfiedBy($data)) {
                continue;
            }

            throw ValidationException::withMessages([
                'checkout' => [sprintf('Complete "%s" first.', $prerequisite->label())],
                'required_step' => [$prerequisite->value],
            ]);
        }
    }

    /**
     * Persist the session and advance its recorded step.
     */
    private function save(CheckoutSession $session, CheckoutStep $completed): CheckoutSession
    {
        $session->forceFill([
            'data' => $session->data,
            'current_step' => $session->nextStep()->value,
        ])->save();

        return $session->refresh();
    }

    private function currentShippingMethod(CheckoutSession $session): ?ShippingMethod
    {
        $id = $session->get('shipping_method_id');

        return $id !== null ? ShippingMethod::query()->find($id) : null;
    }

    /**
     * The zone the session's stored shipping address resolves to, or null when
     * no address has been entered yet.
     *
     * Resolved fresh on every call rather than cached on the session, because
     * an address is editable at any point before placement and a stale zone
     * would silently mis-price shipping after the shopper corrects a typo in
     * their city.
     */
    private function resolveZoneFromSession(CheckoutSession $session): ?ShippingZone
    {
        $address = $session->get('shipping_address');

        if (! is_array($address)) {
            return null;
        }

        return $this->shippingZones->resolveZone(
            country: $address['country'] ?? null,
            state: $address['state'] ?? null,
            city: $address['city'] ?? null,
            postcode: $address['postal_code'] ?? null,
        );
    }

    /**
     * Trim and normalise an address payload.
     *
     * The country is upper-cased here so a comparison anywhere downstream —
     * shipping availability, tax, the invoice — does not have to remember to
     * do it. A method serving "GB" must not be refused because a client sent
     * "gb".
     *
     * @param  array<string, mixed>  $address
     * @return array<string, mixed>
     */
    private function normaliseAddress(array $address): array
    {
        $fields = [
            'first_name', 'last_name', 'company', 'phone', 'email',
            'line1', 'line2', 'city', 'state', 'postal_code', 'country',
            'delivery_instructions',
        ];

        $clean = [];

        foreach ($fields as $field) {
            $value = $address[$field] ?? null;

            if ($value === null) {
                $clean[$field] = null;

                continue;
            }

            $value = trim((string) $value);
            $clean[$field] = $value === '' ? null : $value;
        }

        $clean['country'] = $clean['country'] !== null ? strtoupper($clean['country']) : null;

        return $clean;
    }

    /**
     * A checkout session credential.
     *
     * 32 bytes from a CSPRNG. This token reaches a session holding a name, an
     * address, and a phone number — a short or sequential value would let
     * anyone enumerate strangers' checkouts and read their personal details.
     */
    private function generateToken(): string
    {
        do {
            $token = bin2hex(random_bytes(32));
        } while (CheckoutSession::query()->where('token', $token)->exists());

        return $token;
    }

    /**
     * Release the holds on an abandoned checkout.
     */
    public function abandon(CheckoutSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $this->reservations->releaseForSession($session);

            $session->forceFill(['expires_at' => Carbon::now()])->save();
        });
    }

    public static function sessionHours(): int
    {
        return self::SESSION_HOURS;
    }
}
