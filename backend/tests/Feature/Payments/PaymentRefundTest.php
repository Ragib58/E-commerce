<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Payments\Data\RefundResult;
use App\Payments\PaymentGatewayManager;
use App\Services\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeGateway;
use Tests\TestCase;

/**
 * Refunding through a gateway.
 *
 * The invariant under test is that **the books never claim money was returned
 * when it was not**. A processor that refuses a reversal must leave
 * `refunded_total` untouched — otherwise the store's records show a refund the
 * customer never received, and the balance available for a later legitimate
 * refund is wrong.
 *
 * The offline path matters just as much in the other direction: a cash refund
 * happens in the physical world, and the application must record it without
 * asking a processor to reverse a transaction it never had.
 */
final class PaymentRefundTest extends TestCase
{
    use RefreshDatabase;

    private FakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();

        $this->gateway = new FakeGateway('fake');
        $this->app->make(PaymentGatewayManager::class)->extend('fake', fn (): FakeGateway => $this->gateway);
    }

    private function refunds(): RefundService
    {
        return $this->app->make(RefundService::class);
    }

    /**
     * A delivered, paid order with a real product line and a gateway payment.
     *
     * @return array{Order, Payment, Product}
     */
    private function paidOrder(int $total = 10_000, string $gateway = 'fake'): array
    {
        $product = Product::factory()->published()->create(['price' => $total, 'stock' => 10]);

        $order = Order::factory()->delivered()->totals($total)->create();

        OrderItem::factory()->forProduct($product, 1)->create([
            'order_id' => $order->getKey(),
            'unit_price' => $total,
            'line_total' => $total,
            'tax_total' => 0,
            'stock_was_reduced' => true,
        ]);

        $payment = Payment::factory()->forOrder($order)->paid()->create([
            'gateway' => $gateway,
            'amount' => $total,
            'transaction_reference' => 'txn_original',
        ]);

        return [$order->refresh(), $payment, $product];
    }

    /*
    |--------------------------------------------------------------------------
    | The happy path
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_refund_is_reversed_at_the_gateway(): void
    {
        [$order, $payment] = $this->paidOrder(10_000);

        $refund = $this->refunds()->refund(
            order: $order,
            amount: 4_000,
            actor: Admin::factory()->create(),
            reason: 'One item returned.',
        );

        $this->assertSame(1, $this->gateway->refundCalls, 'The processor must actually be asked.');
        $this->assertTrue($refund->isCompleted());
        $this->assertSame(4_000, (int) $refund->amount);

        // The processor's own reference, for reconciliation against its
        // statements.
        $this->assertSame('refund_'.$payment->uuid, $refund->transaction_reference);
        $this->assertSame('fake', $refund->gateway);

        $this->assertSame(4_000, (int) $order->refresh()->refunded_total);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $order->payment_status);
    }

    #[Test]
    public function a_full_refund_marks_the_order_refunded(): void
    {
        [$order] = $this->paidOrder(10_000);

        $this->refunds()->refund(
            order: $order,
            amount: null,
            actor: Admin::factory()->create(),
            reason: 'Order returned in full.',
        );

        $order->refresh();

        $this->assertSame(10_000, (int) $order->refunded_total);
        $this->assertSame(PaymentStatus::Refunded, $order->payment_status);
    }

    /*
    |--------------------------------------------------------------------------
    | A refused reversal must not touch the books
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_gateway_refusal_leaves_the_order_untouched(): void
    {
        /*
         * The invariant. Writing the refund row before the gateway agreed would
         * mean a refused reversal still incremented `refunded_total` — the
         * books would show money returned that the customer never got, and the
         * balance available for a later refund would be wrong.
         */
        [$order] = $this->paidOrder(10_000);

        $this->gateway->nextRefund = RefundResult::failed('fake', 'Insufficient balance in merchant account.');

        try {
            $this->refunds()->refund(
                order: $order,
                amount: 5_000,
                actor: Admin::factory()->create(),
                reason: 'Attempted refund.',
            );

            $this->fail('A refused refund must throw.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'Insufficient balance',
                $exception->validator->errors()->first('refund'),
            );
        }

        $order->refresh();

        $this->assertSame(0, (int) $order->refunded_total, 'A refused reversal must not move the balance.');
        $this->assertSame(0, $order->refunds()->count(), 'No refund row may survive a refusal.');
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
    }

    #[Test]
    public function a_pending_gateway_refund_is_not_reported_as_completed(): void
    {
        /*
         * Several processors settle refunds hours later. Telling a customer
         * their money is back while the processor has only queued it produces a
         * support call two days afterwards.
         */
        [$order] = $this->paidOrder(10_000);

        $this->gateway->nextRefund = RefundResult::pending('fake', 'refund_queued', 3_000);

        $refund = $this->refunds()->refund(
            order: $order,
            amount: 3_000,
            actor: Admin::factory()->create(),
            reason: 'Queued at the processor.',
        );

        $this->assertSame(Refund::STATUS_PENDING, $refund->status);

        /*
         * The order's balance still moves. The store has committed the money —
         * what is pending is the processor's settlement, not the decision — and
         * leaving the balance untouched would let an admin refund the same
         * amount again while the first was in flight.
         */
        $this->assertSame(3_000, (int) $order->refresh()->refunded_total);
    }

    /*
    |--------------------------------------------------------------------------
    | Offline refunds
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_offline_gateway_is_not_asked_to_reverse_anything(): void
    {
        /*
         * A cash refund is a person handing money back. Asking a processor to
         * reverse a transaction it never had would fail — and failing would
         * block a refund that has already physically happened.
         */
        [$order] = $this->paidOrder(10_000, gateway: 'cash_on_delivery');

        $refund = $this->refunds()->refund(
            order: $order,
            amount: 10_000,
            actor: Admin::factory()->create(),
            reason: 'Cash handed back at the door.',
        );

        $this->assertSame(0, $this->gateway->refundCalls);
        $this->assertTrue($refund->isCompleted());
        $this->assertSame(10_000, (int) $order->refresh()->refunded_total);
    }

    #[Test]
    public function a_payment_with_no_gateway_still_refunds(): void
    {
        // Orders placed before the gateway layer existed have no gateway
        // recorded. They must remain refundable.
        [$order, $payment] = $this->paidOrder(5_000);

        $payment->forceFill(['gateway' => null])->save();

        $refund = $this->refunds()->refund(
            order: $order,
            amount: 5_000,
            actor: Admin::factory()->create(),
            reason: 'Legacy order.',
        );

        $this->assertTrue($refund->isCompleted());
        $this->assertSame(0, $this->gateway->refundCalls);
    }

    /*
    |--------------------------------------------------------------------------
    | Ceilings and duplicates
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_refund_cannot_exceed_what_was_captured(): void
    {
        [$order] = $this->paidOrder(10_000);

        $this->expectException(ValidationException::class);

        $this->refunds()->refund(
            order: $order,
            amount: 15_000,
            actor: Admin::factory()->create(),
            reason: 'Too much.',
        );
    }

    #[Test]
    public function repeated_partial_refunds_are_checked_against_the_running_total(): void
    {
        /*
         * Checked against the running total, not the grand total alone —
         * otherwise three 4,000 refunds against a 10,000 order would each pass
         * individually.
         */
        [$order] = $this->paidOrder(10_000);
        $admin = Admin::factory()->create();

        $this->refunds()->refund($order, 4_000, null, $admin, 'First.');
        $this->refunds()->refund($order->refresh(), 4_000, null, $admin, 'Second.');

        $this->expectException(ValidationException::class);

        $this->refunds()->refund($order->refresh(), 4_000, null, $admin, 'One too many.');
    }

    #[Test]
    public function a_double_clicked_refund_reverses_once(): void
    {
        [$order] = $this->paidOrder(10_000);
        $admin = Admin::factory()->create();

        $first = $this->refunds()->refund(
            order: $order,
            amount: 2_000,
            actor: $admin,
            reason: 'Goodwill.',
            idempotencyKey: 'refund-key-1',
        );

        $second = $this->refunds()->refund(
            order: $order->refresh(),
            amount: 2_000,
            actor: $admin,
            reason: 'Goodwill.',
            idempotencyKey: 'refund-key-1',
        );

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(2_000, (int) $order->refresh()->refunded_total);
        $this->assertSame(1, $order->refunds()->count());

        // And the processor was only asked once.
        $this->assertSame(1, $this->gateway->refundCalls);
    }

    #[Test]
    public function refunding_restocks_when_asked(): void
    {
        [$order, , $product] = $this->paidOrder(10_000);

        $before = (int) $product->fresh()->stock;

        $this->refunds()->refund(
            order: $order,
            amount: null,
            actor: Admin::factory()->create(),
            reason: 'Returned in full.',
            restock: true,
        );

        $this->assertSame($before + 1, (int) $product->fresh()->stock);
    }

    #[Test]
    public function refunding_can_decline_to_restock(): void
    {
        // A damaged item the store does not want back must not go on sale.
        [$order, , $product] = $this->paidOrder(10_000);

        $before = (int) $product->fresh()->stock;

        $this->refunds()->refund(
            order: $order,
            amount: null,
            actor: Admin::factory()->create(),
            reason: 'Damaged, not returned.',
            restock: false,
        );

        $this->assertSame($before, (int) $product->fresh()->stock);
    }

    #[Test]
    public function an_unpaid_order_cannot_be_refunded(): void
    {
        /*
         * Refunding what was never captured is a cancellation. Processing it as
         * a refund would send a gateway request for money the store never took.
         */
        $order = Order::factory()->status(OrderStatus::Confirmed)->totals(5_000)->create();

        $this->expectException(ValidationException::class);

        $this->refunds()->refund(
            order: $order,
            amount: 1_000,
            actor: Admin::factory()->create(),
            reason: 'Nothing to refund.',
        );
    }
}
