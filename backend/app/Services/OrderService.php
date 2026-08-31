<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AddressType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\OrderPlaced;
use App\Events\OrderStatusChanged;
use App\Models\Admin;
use App\Models\Cart;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderNote;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Order creation and lifecycle.
 *
 * ## The three failures this class exists to prevent
 *
 * **Price manipulation.** No figure on an order comes from a request. Unit
 * prices are read from the catalog inside the placing transaction, tax from the
 * store setting, shipping from the chosen method's row. There is no field on any
 * request object that maps to a money column, so the class of bug is absent
 * rather than validated against — the same discipline the cart follows by having
 * no price column at all.
 *
 * **Duplicate orders.** A double-clicked button, a retried request after a
 * timeout, and a replayed payload all present the same idempotency key. The
 * unique index on `orders.idempotency_key` is what stops the second one; a
 * check-then-insert in PHP cannot, because two requests can both pass the check
 * before either inserts. {@see place()} catches the violation and returns the
 * order that won, so the caller sees a success rather than an error for what is,
 * from the shopper's side, one order.
 *
 * **Stock race conditions.** Every line's stock is decremented through
 * InventoryService, which re-reads its row under `lockForUpdate()` inside this
 * transaction. Two orders for the last unit serialise: the second blocks, then
 * sees zero and fails. Reservations taken during checkout narrow the window
 * further, but the lock is what closes it — a reservation is an optimisation for
 * the shopper's experience, not the correctness boundary.
 *
 * ## Status changes
 *
 * {@see transitionTo()} is the only way an order's status moves. It validates
 * against the transition map, writes the audit row, restocks where required, and
 * dispatches the event — all in one transaction. Order::booted() refuses a
 * direct assignment so the four cannot come apart.
 */
final class OrderService
{
    /** Retries when a generated order number collides at INSERT. */
    private const NUMBER_RETRIES = 3;

    public function __construct(
        private readonly CartService $carts,
        private readonly InventoryService $inventory,
        private readonly StockReservationService $reservations,
        private readonly SettingsService $settings,
        private readonly OrderNumberGenerator $numbers,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Placement
    |--------------------------------------------------------------------------
    */

    /**
     * Turn a checkout session into an order.
     *
     * Everything below happens in one transaction: the order, its lines, its
     * addresses, the stock decrements, the reservation commit, the cart clear,
     * and the opening audit row. A partial order is the one outcome worse than
     * a failed one — a customer charged for lines that were never recorded, or
     * stock removed for an order that does not exist.
     *
     * @throws ValidationException
     */
    public function placeFromSession(
        CheckoutSession $session,
        ?string $idempotencyKey = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Order {
        /*
         * A session that already produced an order returns it unchanged.
         *
         * The first line of duplicate defence and the cheapest: a client that
         * retries after a dropped response gets the order it already placed
         * rather than a second one. The unique indexes below are what make the
         * guarantee hold under concurrency; this is what keeps the common case
         * off that path.
         */
        if ($session->order_id !== null) {
            return Order::query()->findOrFail($session->order_id);
        }

        if (! $session->isUsable()) {
            throw ValidationException::withMessages([
                'checkout' => ['This checkout has expired. Please start again.'],
            ]);
        }

        if (! $session->isReadyToPlace()) {
            $next = $session->nextStep();

            throw ValidationException::withMessages([
                'checkout' => [sprintf(
                    'Complete the "%s" step before placing your order.',
                    $next->label(),
                )],
            ]);
        }

        $cart = $session->cart()->first();

        if ($cart === null) {
            throw ValidationException::withMessages([
                'cart' => ['Your cart could not be found.'],
            ]);
        }

        $method = $this->resolvePaymentMethod($session);
        $shippingMethod = $this->resolveShippingMethod($session);

        $order = $this->createOrder(
            cart: $cart,
            session: $session,
            paymentMethod: $method,
            shippingMethod: $shippingMethod,
            idempotencyKey: $idempotencyKey,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        );

        /*
         * Dispatched after the transaction commits, never inside it: a listener
         * that emails a confirmation must not fire for an order that then rolls
         * back. The same rule InventoryService follows.
         */
        OrderPlaced::dispatch($order);

        return $order;
    }

    /**
     * The transactional core.
     *
     * @throws ValidationException
     */
    private function createOrder(
        Cart $cart,
        CheckoutSession $session,
        PaymentMethod $paymentMethod,
        ?ShippingMethod $shippingMethod,
        ?string $idempotencyKey,
        ?string $ipAddress,
        ?string $userAgent,
    ): Order {
        $attempt = 0;

        while (true) {
            try {
                return DB::transaction(function () use (
                    $cart,
                    $session,
                    $paymentMethod,
                    $shippingMethod,
                    $idempotencyKey,
                    $ipAddress,
                    $userAgent,
                ): Order {
                    /*
                     * Priced here, inside the transaction, from the catalog.
                     *
                     * Not from the review step the shopper saw, and not from
                     * anything the client sent. If a price moved between review
                     * and submit, the check below catches it and the shopper is
                     * asked again rather than being charged a figure they did
                     * not agree to.
                     */
                    $summary = $this->carts->summarise($cart);

                    $this->assertCartIsOrderable($summary);

                    $totals = $this->calculateTotals($summary, $shippingMethod);

                    $customer = $session->get('customer', []);
                    $user = $session->user_id !== null
                        ? User::query()->find($session->user_id)
                        : null;

                    $order = $this->insertOrder(
                        session: $session,
                        cart: $cart,
                        user: $user,
                        customer: $customer,
                        paymentMethod: $paymentMethod,
                        shippingMethod: $shippingMethod,
                        totals: $totals,
                        idempotencyKey: $idempotencyKey,
                        ipAddress: $ipAddress,
                        userAgent: $userAgent,
                    );

                    $this->writeLines($order, $summary);
                    $this->writeAddresses($order, $session);

                    /*
                     * Stock comes off here, under InventoryService's row locks.
                     *
                     * After the lines are written, so a failure leaves no stock
                     * removed for an order that does not exist; before the
                     * reservations are committed, so the window the reservation
                     * closed is never reopened.
                     */
                    $this->decrementStock($order);

                    $this->reservations->commitForSession($session, $order);

                    $this->recordInitialStatus($order);

                    $this->attachPayment($order, $paymentMethod);

                    if (($note = $session->get('customer_note')) !== null && trim((string) $note) !== '') {
                        OrderNote::query()->create([
                            'order_id' => $order->getKey(),
                            'user_id' => $user?->getKey(),
                            'author_label' => $order->customer_name,
                            'body' => (string) $note,
                            // The shopper wrote it, so they can see it.
                            'is_customer_visible' => true,
                        ]);
                    }

                    /*
                     * The cart is emptied rather than deleted: the row is the
                     * shopper's ongoing basket, and deleting it would break the
                     * guest token they are still carrying.
                     */
                    $this->carts->clear($cart);

                    $session->forceFill([
                        'order_id' => $order->getKey(),
                        'completed_at' => Carbon::now(),
                    ])->save();

                    return $order->refresh();
                });
            } catch (QueryException $exception) {
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }

                /*
                 * A unique index fired. Two candidates, and they need opposite
                 * responses.
                 *
                 * The idempotency key: a concurrent request placed this exact
                 * order first. Return theirs — the shopper clicked twice and
                 * must end up with one order, not an error.
                 */
                if ($idempotencyKey !== null) {
                    $existing = Order::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();

                    if ($existing !== null) {
                        return $existing;
                    }
                }

                /*
                 * Otherwise the order *number* collided — two orders drew the
                 * same random reference. Retry with a fresh one. Bounded,
                 * because an unbounded retry on a violation we have
                 * misdiagnosed would spin.
                 */
                if (++$attempt >= self::NUMBER_RETRIES) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Insert the order row.
     *
     * @param  array<string, mixed>  $customer
     * @param  array{subtotal: int, discount: int, tax: int, shipping: int, total: int}  $totals
     */
    private function insertOrder(
        CheckoutSession $session,
        Cart $cart,
        ?User $user,
        array $customer,
        PaymentMethod $paymentMethod,
        ?ShippingMethod $shippingMethod,
        array $totals,
        ?string $idempotencyKey,
        ?string $ipAddress,
        ?string $userAgent,
    ): Order {
        $order = new Order([
            'order_number' => $this->numbers->generate(),
            'user_id' => $user?->getKey(),
            'customer_name' => (string) ($customer['name'] ?? $user?->name ?? 'Customer'),
            'customer_email' => (string) ($customer['email'] ?? $user?->email ?? ''),
            'customer_phone' => $customer['phone'] ?? $user?->phone,
            'is_guest' => $user === null,
            'payment_method' => $paymentMethod,
            'shipping_method_id' => $shippingMethod?->getKey(),
            // Snapshotted, so renaming the method later does not rewrite this
            // order's record of what was chosen.
            'shipping_method_name' => $shippingMethod?->name,
            'currency' => (string) $this->settings->get('business.currency', 'USD'),
            'tax_rate' => (float) $this->settings->get('business.tax_rate', 0),
            'coupon_code' => $cart->coupon_code,
            'customer_note' => $session->get('customer_note'),
            'idempotency_key' => $idempotencyKey,
            'cart_id' => $cart->getKey(),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent !== null ? substr($userAgent, 0, 512) : null,
        ]);

        /*
         * Money and status are set with forceFill because they are not
         * mass-assignable — a fillable total is a total something can be told.
         * See Order::$fillable.
         */
        Order::withStatusWrites(function () use ($order, $totals): void {
            $order->forceFill([
                'status' => OrderStatus::Pending,
                'payment_status' => PaymentStatus::Pending,
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount'],
                'tax_total' => $totals['tax'],
                'shipping_total' => $totals['shipping'],
                'grand_total' => $totals['total'],
                'placed_at' => Carbon::now(),
            ])->save();
        });

        return $order;
    }

    /**
     * Copy the priced cart lines onto the order.
     *
     * The snapshot: names, skus, variant labels, and prices are all captured so
     * an invoice renders correctly years later, after the catalog has moved on.
     *
     * @param  array<string, mixed>  $summary
     */
    private function writeLines(Order $order, array $summary): void
    {
        $taxRate = (float) $order->tax_rate;

        foreach ($summary['items'] as $line) {
            if (! $line['is_available']) {
                continue;
            }

            $lineTotal = (int) $line['line_total'];

            /*
             * Tax attributed per line so an invoice can show it per line and so
             * a partial refund knows how much tax to return with the goods.
             *
             * The order's `tax_total` is computed once over the taxable
             * subtotal — see calculateTotals — rather than summed from these,
             * because rounding each line accumulates a fraction of a penny per
             * item and a ten-line order would disagree with itself.
             */
            $lineTax = $line['is_taxable'] && $taxRate > 0
                ? (int) round($lineTotal * ($taxRate / 100))
                : 0;

            OrderItem::query()->create([
                'order_id' => $order->getKey(),
                'product_id' => $this->productIdFor($line),
                'product_variant_id' => $this->variantIdFor($line),

                'product_name' => $line['product']['name'],
                'product_sku' => $line['product']['sku'],
                'variant_name' => $line['variant']['name'] ?? null,
                'product_type' => $line['product']['type'],
                'variant_options' => $line['variant']['options'] ?? null,
                'options' => $line['options'],
                'thumbnail_url' => $line['product']['thumbnail'],

                'quantity' => (int) $line['quantity'],
                'unit_price' => (int) $line['unit_price'],
                'list_price' => $line['list_price'] !== null ? (int) $line['list_price'] : null,
                'discount_total' => (int) $line['line_discount'],
                'tax_total' => $lineTax,
                'line_total' => $lineTotal,
                'is_taxable' => (bool) $line['is_taxable'],
                'stock_was_reduced' => false,
            ]);
        }

        if ($order->items()->count() === 0) {
            throw ValidationException::withMessages([
                'cart' => ['None of the items in your cart are available to order.'],
            ]);
        }
    }

    /**
     * Copy the checkout's addresses onto the order.
     */
    private function writeAddresses(Order $order, CheckoutSession $session): void
    {
        $shipping = $session->get('shipping_address');

        if (is_array($shipping)) {
            $order->addresses()->create($this->addressPayload($shipping, AddressType::Shipping));
        }

        /*
         * A shopper who said "billing is the same" gets a real second row
         * rather than a null and a flag. An invoice must be able to print a
         * billing address without knowing how it was collected, and resolving
         * the flag at every read is a branch that eventually gets forgotten in
         * one of the places that reads it.
         */
        $billing = $session->get('billing_address');

        if (! is_array($billing) && $session->get('billing_same_as_shipping') === true) {
            $billing = $shipping;
        }

        if (is_array($billing)) {
            $order->addresses()->create($this->addressPayload($billing, AddressType::Billing));
        }
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array<string, mixed>
     */
    private function addressPayload(array $address, AddressType $type): array
    {
        return [
            'type' => $type,
            'first_name' => (string) ($address['first_name'] ?? ''),
            'last_name' => (string) ($address['last_name'] ?? ''),
            'company' => $address['company'] ?? null,
            'phone' => $address['phone'] ?? null,
            'email' => $address['email'] ?? null,
            'line1' => (string) ($address['line1'] ?? ''),
            'line2' => $address['line2'] ?? null,
            'city' => (string) ($address['city'] ?? ''),
            'state' => $address['state'] ?? null,
            'postal_code' => $address['postal_code'] ?? null,
            'country' => strtoupper((string) ($address['country'] ?? '')),
            'delivery_instructions' => $address['delivery_instructions'] ?? null,
        ];
    }

    /**
     * Take the stock for every line that holds any.
     *
     * Delegated to InventoryService, which is the only writer of a stock level
     * and which re-reads each row under `lockForUpdate()` inside this
     * transaction. That lock is what makes two orders for the last unit
     * serialise rather than both succeed.
     *
     * @throws ValidationException when a line can no longer be fulfilled.
     */
    private function decrementStock(Order $order): void
    {
        foreach ($order->items()->get() as $item) {
            $stockable = $item->stockable();

            if ($stockable === null) {
                continue;
            }

            $product = $stockable instanceof ProductVariant
                ? $stockable->product
                : $stockable;

            if ($product === null || ! $product->type->tracksInventory()) {
                continue;
            }

            // Backordered lines are deliberately allowed to go negative —
            // that is what backorder means — and InventoryService permits it
            // for exactly those rows.
            $this->inventory->decrementForSale($stockable, (int) $item->quantity, $order);

            $item->forceFill(['stock_was_reduced' => true])->save();
        }
    }

    /**
     * The opening row of the audit trail.
     *
     * Written for every order so the timeline starts at placement rather than
     * at the first change — an order whose history begins with "Pending →
     * Confirmed" leaves the reader to infer when it was placed.
     */
    private function recordInitialStatus(Order $order): void
    {
        OrderStatusHistory::query()->create([
            'order_id' => $order->getKey(),
            'stream' => OrderStatusHistory::STREAM_ORDER,
            'from_status' => null,
            'to_status' => $order->status->value,
            'user_id' => $order->user_id,
            'actor_label' => $order->customer_name,
            'comment' => 'Order placed.',
        ]);
    }

    /**
     * Record the intended payment.
     *
     * Offline methods produce a Pending payment row and, for cash on delivery,
     * an immediately Confirmed order — the store has agreed to ship before
     * being paid. Online methods are refused earlier, in CheckoutService, since
     * no gateway is wired up; an order that reports itself Paid because nothing
     * rejected it is the worst available outcome.
     */
    private function attachPayment(Order $order, PaymentMethod $method): void
    {
        Payment::query()->create([
            'order_id' => $order->getKey(),
            'method' => $method,
            'status' => Payment::STATUS_PENDING,
            'amount' => (int) $order->grand_total,
            'currency' => $order->currency,
        ]);

        if ($method->confirmsImmediately()) {
            $this->transitionTo(
                $order,
                OrderStatus::Confirmed,
                comment: 'Confirmed automatically — payment on delivery.',
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    */

    /**
     * The order's money, computed from the priced cart and the shipping method.
     *
     * @param  array<string, mixed>  $summary
     * @return array{subtotal: int, discount: int, tax: int, shipping: int, total: int}
     */
    public function calculateTotals(array $summary, ?ShippingMethod $shippingMethod): array
    {
        $subtotal = (int) $summary['totals']['subtotal'];
        $discount = (int) $summary['totals']['discount'];
        $tax = (int) $summary['totals']['tax'];

        // Read from the method's own row, never from the client. The free-above
        // threshold is applied by the model so the quote and the order cannot
        // disagree about whether shipping was free.
        $shipping = $shippingMethod?->rateFor($subtotal) ?? 0;

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'shipping' => $shipping,
            // The identity Order::totalsReconcile() asserts. `discount` is
            // already reflected in the line totals that make up `subtotal`, so
            // it is reported for display rather than subtracted twice.
            'total' => $subtotal + $tax + $shipping,
        ];
    }

    /**
     * Refuse a cart that cannot become an order.
     *
     * @param  array<string, mixed>  $summary
     *
     * @throws ValidationException
     */
    private function assertCartIsOrderable(array $summary): void
    {
        if ($summary['items'] === []) {
            throw ValidationException::withMessages([
                'cart' => ['Your cart is empty.'],
            ]);
        }

        $unavailable = collect($summary['items'])
            ->reject(fn (array $line): bool => $line['is_available'])
            ->map(fn (array $line): string => $line['product']['name'])
            ->values();

        if ($unavailable->isNotEmpty()) {
            throw ValidationException::withMessages([
                'cart' => [sprintf(
                    'These items are no longer available: %s. Remove them to continue.',
                    $unavailable->join(', '),
                )],
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Status transitions
    |--------------------------------------------------------------------------
    */

    /**
     * Move an order to a new status.
     *
     * The single path for a status change. Validates against the transition
     * map, writes the audit row, restocks where required, stamps the lifecycle
     * timestamp, and dispatches the event — one transaction, so an order can
     * never end up in a state whose side effects half happened.
     *
     * @throws ValidationException on an illegal transition.
     */
    public function transitionTo(
        Order $order,
        OrderStatus $target,
        Admin|User|null $actor = null,
        ?string $comment = null,
        bool $restock = true,
    ): Order {
        $updated = DB::transaction(function () use ($order, $target, $actor, $comment, $restock): Order {
            /*
             * Re-read under a row lock.
             *
             * The instance passed in may have been loaded before another
             * request moved it — two admins clicking "Ship" and "Cancel" at the
             * same moment would otherwise both validate against the same stale
             * Pending and both write. The lock makes the second one see the
             * first one's result and fail the transition check.
             */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            $from = $locked->status;

            if (! $from->canTransitionTo($target)) {
                throw ValidationException::withMessages([
                    'status' => [sprintf(
                        'An order that is %s cannot be marked %s.',
                        strtolower($from->label()),
                        strtolower($target->label()),
                    )],
                ]);
            }

            /*
             * Restock before the status is written, while `holdsStock()` still
             * describes the state the units are actually in. Reading it after
             * the write would ask the *new* status whether the *old* one held
             * stock.
             */
            if ($restock && $from->holdsStock() && ! $target->holdsStock()) {
                $this->restock($locked, $actor instanceof Admin ? $actor : null);
            }

            Order::withStatusWrites(function () use ($locked, $target): void {
                $locked->forceFill(array_merge(
                    ['status' => $target],
                    $this->timestampFor($target),
                ))->save();
            });

            OrderStatusHistory::query()->create([
                'order_id' => $locked->getKey(),
                'stream' => OrderStatusHistory::STREAM_ORDER,
                'from_status' => $from->value,
                'to_status' => $target->value,
                'admin_id' => $actor instanceof Admin ? $actor->getKey() : null,
                'user_id' => $actor instanceof User ? $actor->getKey() : null,
                'actor_label' => $this->actorLabel($actor),
                'comment' => $comment,
                'notified_customer' => $target->notifiesCustomer(),
            ]);

            return $locked;
        });

        /*
         * Dispatched after the transaction commits. A listener that emails the
         * customer "your order has shipped" must not fire for a transition that
         * then rolls back — the same rule InventoryService follows.
         */
        OrderStatusChanged::dispatch($updated, $updated->status, $comment);

        return $updated->refresh();
    }

    /**
     * The lifecycle timestamp a status implies.
     *
     * @return array<string, Carbon>
     */
    private function timestampFor(OrderStatus $status): array
    {
        return match ($status) {
            OrderStatus::Confirmed => ['confirmed_at' => Carbon::now()],
            OrderStatus::Shipped => ['shipped_at' => Carbon::now()],
            OrderStatus::Delivered => ['delivered_at' => Carbon::now()],
            OrderStatus::Cancelled => ['cancelled_at' => Carbon::now()],
            OrderStatus::Refunded => ['refunded_at' => Carbon::now()],
            default => [],
        };
    }

    /**
     * Return an order's units to the shelf.
     *
     * Only lines that actually took stock, tracked by `stock_was_reduced` on
     * the line rather than re-derived from the product's current type — a
     * product converted from simple to digital after the order would otherwise
     * either strand its units or create some from nothing.
     *
     * The flag is cleared as each line is returned, so a cancel followed by a
     * refund cannot restock twice.
     */
    private function restock(Order $order, ?Admin $actor): void
    {
        foreach ($order->items()->get() as $item) {
            if (! $item->stock_was_reduced) {
                continue;
            }

            $stockable = $item->stockable();

            if ($stockable === null) {
                // The catalog row is gone. The money still moves; there is
                // simply nowhere to put the units back.
                $item->forceFill(['stock_was_reduced' => false])->save();

                continue;
            }

            $this->inventory->returnToStock($stockable, (int) $item->quantity, $order, $actor);

            $item->forceFill(['stock_was_reduced' => false])->save();
        }
    }

    /**
     * Cancel an order.
     *
     * A named method rather than a bare transitionTo, because cancellation is
     * the transition with the most callers and the most rules — and the
     * customer-facing check is stricter than the staff one.
     *
     * @throws ValidationException
     */
    public function cancel(
        Order $order,
        Admin|User|null $actor = null,
        ?string $reason = null,
    ): Order {
        if (! $order->status->isCancellable()) {
            throw ValidationException::withMessages([
                'status' => [sprintf(
                    'An order that is %s can no longer be cancelled.',
                    strtolower($order->status->label()),
                )],
            ]);
        }

        // A customer's own cancellation stops earlier than an admin's: past
        // Confirmed, staff may already be holding the item.
        if ($actor instanceof User && ! $order->status->isCustomerCancellable()) {
            throw ValidationException::withMessages([
                'status' => ['This order is already being prepared. Contact us and we will help.'],
            ]);
        }

        return $this->transitionTo(
            $order,
            OrderStatus::Cancelled,
            $actor,
            $reason ?? 'Order cancelled.',
        );
    }

    /**
     * Change an order's payment status.
     *
     * Separate from the order status because the two move independently — a
     * cash-on-delivery order ships while payment is Pending, and a Delivered
     * order can later be Refunded. Recorded in the same history table so the
     * timeline reads as one sequence.
     */
    public function setPaymentStatus(
        Order $order,
        PaymentStatus $target,
        Admin|User|null $actor = null,
        ?string $comment = null,
    ): Order {
        $updated = DB::transaction(function () use ($order, $target, $actor, $comment): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            $from = $locked->payment_status;

            if ($from === $target) {
                return $locked;
            }

            Order::withStatusWrites(function () use ($locked, $target): void {
                $locked->forceFill(array_merge(
                    ['payment_status' => $target],
                    $target === PaymentStatus::Refunded ? ['refunded_at' => Carbon::now()] : [],
                ))->save();
            });

            OrderStatusHistory::query()->create([
                'order_id' => $locked->getKey(),
                'stream' => OrderStatusHistory::STREAM_PAYMENT,
                'from_status' => $from->value,
                'to_status' => $target->value,
                'admin_id' => $actor instanceof Admin ? $actor->getKey() : null,
                'user_id' => $actor instanceof User ? $actor->getKey() : null,
                'actor_label' => $this->actorLabel($actor),
                'comment' => $comment,
            ]);

            return $locked;
        });

        return $updated->refresh();
    }

    /**
     * Mark an order paid and confirm it if it is still waiting.
     *
     * The two go together for every method: money arriving is what makes an
     * order ready to pick. Confirming is skipped when the order has already
     * moved past Pending, so recording a late bank transfer against an order
     * already being packed does not attempt an illegal backwards transition.
     */
    public function markPaid(
        Order $order,
        Admin|User|null $actor = null,
        ?string $comment = null,
    ): Order {
        $order = $this->setPaymentStatus($order, PaymentStatus::Paid, $actor, $comment ?? 'Payment received.');

        $order->payments()
            ->where('status', Payment::STATUS_PENDING)
            ->update([
                'status' => Payment::STATUS_PAID,
                'paid_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        if ($order->status === OrderStatus::Pending) {
            $order = $this->transitionTo($order, OrderStatus::Confirmed, $actor, 'Payment received.');
        }

        return $order;
    }

    /*
    |--------------------------------------------------------------------------
    | Notes and tracking
    |--------------------------------------------------------------------------
    */

    /**
     * Add a note to an order's thread.
     *
     * `$isCustomerVisible` defaults to false. An internal note surfaced on a
     * customer's order page is a serious incident, so the parameter that
     * exposes it must be passed deliberately — see the migration.
     */
    public function addNote(
        Order $order,
        string $body,
        Admin|User|null $author = null,
        bool $isCustomerVisible = false,
    ): OrderNote {
        return OrderNote::query()->create([
            'order_id' => $order->getKey(),
            'admin_id' => $author instanceof Admin ? $author->getKey() : null,
            'user_id' => $author instanceof User ? $author->getKey() : null,
            'author_label' => $this->actorLabel($author),
            'body' => $body,
            'is_customer_visible' => $isCustomerVisible,
        ]);
    }

    /**
     * Record carrier tracking details.
     *
     * Does not itself ship the order: an admin may have the tracking number
     * before the parcel is collected, and forcing the two together would either
     * ship it early or lose the number.
     */
    public function setTracking(
        Order $order,
        ?string $trackingNumber,
        ?string $trackingUrl = null,
        ?Admin $actor = null,
    ): Order {
        $order->forceFill([
            'tracking_number' => $trackingNumber,
            'tracking_url' => $trackingUrl,
        ])->save();

        if ($trackingNumber !== null) {
            $this->addNote(
                $order,
                sprintf('Tracking number set to %s.', $trackingNumber),
                $actor,
                isCustomerVisible: true,
            );
        }

        return $order->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @throws ValidationException
     */
    private function resolvePaymentMethod(CheckoutSession $session): PaymentMethod
    {
        $value = $session->get('payment_method');
        $method = $value !== null ? PaymentMethod::tryFrom((string) $value) : null;

        if ($method === null) {
            throw ValidationException::withMessages([
                'payment_method' => ['Choose a payment method.'],
            ]);
        }

        // Re-checked at placement, not only when it was chosen: a method
        // disabled by an admin between the payment step and the final click
        // must not still produce an order.
        if (! $method->isEnabled($this->settings)) {
            throw ValidationException::withMessages([
                'payment_method' => ['That payment method is no longer available.'],
            ]);
        }

        if ($method->requiresGateway()) {
            throw ValidationException::withMessages([
                'payment_method' => ['Online payment is not available yet. Choose cash on delivery or bank transfer.'],
            ]);
        }

        return $method;
    }

    /**
     * @throws ValidationException
     */
    private function resolveShippingMethod(CheckoutSession $session): ?ShippingMethod
    {
        $id = $session->get('shipping_method_id');

        if ($id === null) {
            return null;
        }

        $method = ShippingMethod::query()->find($id);

        if ($method === null || ! $method->is_active) {
            throw ValidationException::withMessages([
                'shipping_method' => ['That delivery method is no longer available. Please choose another.'],
            ]);
        }

        return $method;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function productIdFor(array $line): ?int
    {
        return Product::query()
            ->where('uuid', $line['product']['id'])
            ->value('id');
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function variantIdFor(array $line): ?int
    {
        if (($line['variant']['id'] ?? null) === null) {
            return null;
        }

        return ProductVariant::query()
            ->where('uuid', $line['variant']['id'])
            ->value('id');
    }

    private function actorLabel(Admin|User|null $actor): string
    {
        if ($actor instanceof Admin) {
            return $actor->name;
        }

        if ($actor instanceof User) {
            return $actor->name;
        }

        return 'System';
    }

    /**
     * Whether a query exception is a unique-constraint violation.
     *
     * By SQLSTATE rather than message text, which differs between MySQL and the
     * SQLite the test suite runs on.
     */
    private function isUniqueViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23000' || $exception->getCode() === '23505';
    }
}
