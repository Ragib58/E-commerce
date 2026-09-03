<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Refund;
use App\Notifications\RefundProcessedNotification;
use App\Payments\Data\RefundResult;
use App\Payments\Exceptions\PaymentException;
use App\Payments\PaymentGatewayManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Returning money to a customer.
 *
 * ## The invariant
 *
 * **A store can never refund more than it took.** `orders.refunded_total` is the
 * running sum, and every refund is checked against it *inside* a transaction
 * with the order row locked. Checking outside the lock is the classic
 * over-refund bug: two admins clicking "refund £50" on a £50 order both read
 * "£50 refundable" and both pay out.
 *
 * ## Partial refunds are the normal case
 *
 * One line of five returned, a shipping fee waived after a late delivery. So a
 * refund carries an optional line breakdown, and the order's payment status
 * distinguishes PartiallyRefunded from Refunded — an order with money still
 * owed to the store is in a materially different position from one that has
 * been made whole.
 *
 * ## Restocking is a decision, not a consequence
 *
 * Refunding a damaged item the store does not want back is ordinary. Silently
 * restocking it would put a broken product back on sale, so `$restock` is an
 * explicit argument recorded on the refund row.
 */
final class RefundService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly OrderService $orders,
        private readonly PaymentGatewayManager $gateways,
    ) {}

    /**
     * Refund an amount against an order.
     *
     * @param  int|null  $amount  Minor units, or null for the full remaining balance.
     * @param  array<int, array{order_item_id: int, quantity: int}>|null  $lines
     *
     * @throws ValidationException
     */
    public function refund(
        Order $order,
        ?int $amount = null,
        ?array $lines = null,
        ?Admin $actor = null,
        ?string $reason = null,
        bool $restock = false,
        ?string $idempotencyKey = null,
    ): Refund {
        try {
            $refund = DB::transaction(function () use (
                $order,
                $amount,
                $lines,
                $actor,
                $reason,
                $restock,
                $idempotencyKey,
            ): Refund {
                /*
                 * Locked before the balance is read.
                 *
                 * Everything below — the ceiling check, the new total, the
                 * resulting payment status — depends on `refunded_total`, and
                 * an unlocked read makes all three racy. See the class docblock.
                 */
                $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

                $this->assertRefundable($locked);

                $resolvedLines = $lines !== null
                    ? $this->resolveLines($locked, $lines)
                    : null;

                // A line-itemised refund derives its amount from the lines, so
                // the money and the goods cannot disagree. An order-level one
                // takes the amount given, or the whole remaining balance.
                $resolvedAmount = $resolvedLines !== null
                    ? array_sum(array_column($resolvedLines, 'amount'))
                    : ($amount ?? $locked->refundable_amount);

                $this->assertAmount($locked, $resolvedAmount);

                /*
                 * The idempotency check has to happen HERE, before the gateway
                 * is called.
                 *
                 * The unique index on `refunds.idempotency_key` catches a
                 * duplicate — but only at INSERT, which is after the reversal
                 * has already been sent. A double-clicked refund button would
                 * therefore pay the customer twice at the processor and then
                 * quietly return the first refund row, hiding the second
                 * payout entirely.
                 *
                 * The read runs inside the transaction with the order row
                 * already locked, so a concurrent duplicate blocks on that lock
                 * until the first has committed and then sees it. The unique
                 * index remains as the backstop for the case this read cannot
                 * cover — two requests that somehow reach the insert together.
                 */
                if ($idempotencyKey !== null) {
                    $existing = Refund::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();

                    if ($existing !== null) {
                        return $existing;
                    }
                }

                $payment = $this->originalPayment($locked);

                /*
                 * Reverse at the processor FIRST, then record.
                 *
                 * The order matters. Writing the refund row before the gateway
                 * has agreed would mean a refused reversal still incremented
                 * `refunded_total` — the store's books would show money
                 * returned that the customer never received, and the balance
                 * available for a later, legitimate refund would be wrong.
                 *
                 * Doing it inside the transaction means a gateway failure rolls
                 * the whole thing back and leaves no trace of a refund that did
                 * not happen.
                 */
                $result = $this->reverseAtGateway($payment, $resolvedAmount, $reason);

                if ($result !== null && $result->isFailed()) {
                    throw ValidationException::withMessages([
                        'refund' => [$result->failureReason ?? 'The payment provider refused the refund.'],
                    ]);
                }

                $refund = Refund::query()->create([
                    'order_id' => $locked->getKey(),
                    'payment_id' => $payment?->getKey(),
                    'amount' => $resolvedAmount,
                    'currency' => $locked->currency,

                    /*
                     * A gateway that settles asynchronously leaves this Pending.
                     * Reporting a queued reversal as completed tells a customer
                     * their money is back while the processor has only accepted
                     * the instruction — and produces a support call two days
                     * later.
                     *
                     * An offline refund is Completed because, from the
                     * application's side, it is: the cash changed hands in the
                     * physical world and this is the record of it.
                     */
                    'status' => $result !== null && $result->isPending()
                        ? Refund::STATUS_PENDING
                        : Refund::STATUS_COMPLETED,

                    'admin_id' => $actor?->getKey(),
                    'actor_label' => $actor?->name ?? 'System',
                    'reason' => $reason,
                    'line_items' => $resolvedLines,
                    'is_restocked' => $restock,
                    'idempotency_key' => $idempotencyKey,

                    // What the processor called this reversal, for
                    // reconciliation against its own statements.
                    'gateway' => $payment?->gateway,
                    'transaction_reference' => $result?->reference,
                    'gateway_response' => $result?->raw,

                    'refunded_at' => Carbon::now(),
                ]);

                $newTotal = (int) $locked->refunded_total + $resolvedAmount;

                $locked->forceFill(['refunded_total' => $newTotal])->save();

                if ($resolvedLines !== null) {
                    $this->markLinesRefunded($resolvedLines);
                }

                if ($restock) {
                    $this->restockLines($locked, $resolvedLines, $actor);
                }

                /*
                 * Full versus partial is decided by arithmetic, not by which
                 * button was pressed. An admin issuing three partial refunds
                 * that happen to sum to the total has fully refunded the order,
                 * and it should say so.
                 */
                $paymentStatus = $newTotal >= (int) $locked->grand_total
                    ? PaymentStatus::Refunded
                    : PaymentStatus::PartiallyRefunded;

                $this->orders->setPaymentStatus(
                    $locked,
                    $paymentStatus,
                    $actor,
                    sprintf('Refunded %s.', $this->formatAmount($resolvedAmount, $locked->currency)),
                );

                /*
                 * The order status follows only on a *full* refund, and only
                 * from a state that permits it. A partially refunded order is
                 * still being fulfilled — moving it to Refunded would take it
                 * out of the warehouse's queue with goods still to send.
                 */
                if ($paymentStatus === PaymentStatus::Refunded
                    && $locked->status->canTransitionTo(OrderStatus::Refunded)) {
                    $this->orders->transitionTo(
                        $locked,
                        OrderStatus::Refunded,
                        $actor,
                        $reason ?? 'Order fully refunded.',
                        // Already handled above, per the caller's choice.
                        restock: false,
                    );
                }

                return $refund;
            });
        } catch (QueryException $exception) {
            /*
             * The idempotency index fired: a double-clicked refund button. The
             * refund that won is returned rather than an error — the admin
             * intended one refund and got one.
             */
            if ($idempotencyKey !== null && $this->isUniqueViolation($exception)) {
                $existing = Refund::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            throw $exception;
        }

        $refund = $refund->refresh();

        /*
         * Notified only here, on the path that actually created a new refund
         * row — never for the idempotent-replay branch above, which returns an
         * *existing* refund a customer has already been told about. Sending a
         * second "refund processed" email for a double-clicked button would be
         * exactly the duplicate-notification failure the idempotency key
         * exists to prevent in the first place.
         */
        $this->notifyRefund($order, $refund);

        return $refund;
    }

    /**
     * Send the customer's refund confirmation and, when the account and
     * relations were not already loaded by the caller, load what the
     * notification needs.
     */
    private function notifyRefund(Order $order, Refund $refund): void
    {
        $order = $order->fresh(['user']) ?? $order;

        if ($order->user_id !== null && $order->user !== null) {
            $order->user->notify(new RefundProcessedNotification($order, $refund));

            return;
        }

        Notification::route('mail', $order->customer_email)
            ->notify(new RefundProcessedNotification($order, $refund));
    }

    /**
     * @throws ValidationException
     */
    private function assertRefundable(Order $order): void
    {
        if (! $order->payment_status->isRefundable()) {
            throw ValidationException::withMessages([
                'refund' => [$order->payment_status->awaitsPayment()
                    ? 'This order has not been paid, so there is nothing to refund. Cancel it instead.'
                    : 'This order has already been fully refunded.'],
            ]);
        }

        if (! $order->status->isRefundable()) {
            throw ValidationException::withMessages([
                'refund' => [sprintf(
                    'An order that is %s cannot be refunded.',
                    strtolower($order->status->label()),
                )],
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertAmount(Order $order, int $amount): void
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['A refund must be for a positive amount.'],
            ]);
        }

        $remaining = $order->refundable_amount;

        if ($amount > $remaining) {
            throw ValidationException::withMessages([
                'amount' => [sprintf(
                    'That exceeds the refundable balance of %s.',
                    $this->formatAmount($remaining, $order->currency),
                )],
            ]);
        }
    }

    /**
     * Turn requested line quantities into amounts.
     *
     * The amount per line is computed from the *stored* unit price and its
     * share of tax, never from anything the caller sent — the same rule that
     * governs placement. An admin can choose which lines and how many units,
     * not what they are worth.
     *
     * @param  array<int, array{order_item_id: int, quantity: int}>  $lines
     * @return array<int, array{order_item_id: int, quantity: int, amount: int}>
     *
     * @throws ValidationException
     */
    private function resolveLines(Order $order, array $lines): array
    {
        $resolved = [];

        foreach ($lines as $line) {
            $item = OrderItem::query()
                ->where('order_id', $order->getKey())
                ->whereKey($line['order_item_id'])
                ->first();

            if ($item === null) {
                // Scoped to this order, so an item id from another order is
                // refused rather than silently refunding against the wrong one.
                throw ValidationException::withMessages([
                    'lines' => ['One of the selected items is not on this order.'],
                ]);
            }

            $quantity = (int) $line['quantity'];

            if ($quantity <= 0) {
                continue;
            }

            if ($quantity > $item->refundable_quantity) {
                throw ValidationException::withMessages([
                    'lines' => [sprintf(
                        'Only %d of "%s" can still be refunded.',
                        $item->refundable_quantity,
                        $item->displayName(),
                    )],
                ]);
            }

            /*
             * The unit's share of tax travels with it. Refunding the goods but
             * keeping the tax on them shorts the customer by the tax rate, and
             * it is the kind of error nobody notices until an auditor does.
             */
            $unitTax = $item->quantity > 0
                ? (int) round((int) $item->tax_total / (int) $item->quantity)
                : 0;

            $resolved[] = [
                'order_item_id' => (int) $item->getKey(),
                'quantity' => $quantity,
                'amount' => ((int) $item->unit_price + $unitTax) * $quantity,
            ];
        }

        if ($resolved === []) {
            throw ValidationException::withMessages([
                'lines' => ['Select at least one item to refund.'],
            ]);
        }

        return $resolved;
    }

    /**
     * @param  array<int, array{order_item_id: int, quantity: int, amount: int}>  $lines
     */
    private function markLinesRefunded(array $lines): void
    {
        foreach ($lines as $line) {
            OrderItem::query()
                ->whereKey($line['order_item_id'])
                ->increment('refunded_quantity', $line['quantity']);
        }
    }

    /**
     * Return refunded units to the shelf.
     *
     * With named lines, only those units. Without them — an order-level refund
     * — everything the order still holds, because a full refund of a whole
     * order means the whole order came back.
     *
     * @param  array<int, array{order_item_id: int, quantity: int, amount: int}>|null  $lines
     */
    private function restockLines(Order $order, ?array $lines, ?Admin $actor): void
    {
        if ($lines === null) {
            foreach ($order->items()->get() as $item) {
                if (! $item->stock_was_reduced) {
                    continue;
                }

                $stockable = $item->stockable();

                if ($stockable !== null) {
                    $this->inventory->returnToStock($stockable, (int) $item->quantity, $order, $actor);
                }

                // Cleared so a later cancellation cannot restock these units a
                // second time.
                $item->forceFill(['stock_was_reduced' => false])->save();
            }

            return;
        }

        foreach ($lines as $line) {
            $item = OrderItem::query()->find($line['order_item_id']);

            if ($item === null || ! $item->stock_was_reduced) {
                continue;
            }

            $stockable = $item->stockable();

            if ($stockable !== null) {
                $this->inventory->returnToStock($stockable, $line['quantity'], $order, $actor);
            }
        }
    }

    /**
     * The payment a refund reverses.
     *
     * The most recent successful one. An order paid in a single attempt has
     * exactly one; an order that failed twice before succeeding should reverse
     * the attempt that actually took the money.
     */
    /**
     * Ask the processor to return the money, when it is able to.
     *
     * Returns null when there is nothing to reverse remotely — an offline
     * gateway, or a payment that never captured. That is not a failure: a cash
     * refund is a person handing money back, and the application's job is only
     * to record that it happened.
     *
     * `supportsRefunds()` is consulted rather than the gateway being called
     * speculatively, because asking a processor to reverse a transaction it
     * never had would fail — and failing would block a refund that has already
     * physically occurred.
     *
     * @throws PaymentException when the gateway is unreachable.
     */
    private function reverseAtGateway(?Payment $payment, int $amount, ?string $reason): ?RefundResult
    {
        if ($payment === null || ! $payment->isRefundable()) {
            return null;
        }

        $identifier = $payment->gateway;

        if (! is_string($identifier) || $identifier === '' || ! $this->gateways->has($identifier)) {
            return null;
        }

        $gateway = $this->gateways->gateway($identifier);

        if (! $gateway->supportsRefunds()) {
            return null;
        }

        return $gateway->refund($payment, $amount, $reason);
    }

    private function originalPayment(Order $order): ?Payment
    {
        return $order->payments()->successful()->latest('id')->first()
            ?? $order->payments()->latest('id')->first();
    }

    /**
     * Minor units as a readable string, for a history comment.
     */
    private function formatAmount(int $minorUnits, string $currency): string
    {
        return sprintf('%s %s', $currency, number_format($minorUnits / 100, 2));
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23000' || $exception->getCode() === '23505';
    }
}
