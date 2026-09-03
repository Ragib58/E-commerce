<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderStatusChanged;
use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Order status changes.
 *
 * The transition map is the thing under test. Its value is that it is the
 * *only* answer to "can this order move there" — so these assertions cover both
 * halves: that legal moves work and record what they did, and that illegal ones
 * are refused no matter which entry point is used.
 *
 * The restocking assertions matter as much as the transitions. A cancelled
 * order that does not return its units silently loses inventory; one that
 * returns them twice invents it.
 */
final class OrderStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();
    }

    private function orders(): OrderService
    {
        return app(OrderService::class);
    }

    /**
     * An order holding real stock, so restocking is observable.
     */
    private function orderHoldingStock(int $stock = 10, int $quantity = 3): array
    {
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => $stock]);

        $order = Order::factory()->paid()->create();

        OrderItem::factory()
            ->forProduct($product, $quantity)
            ->create(['order_id' => $order->getKey(), 'stock_was_reduced' => true]);

        return [$order->refresh(), $product];
    }

    /*
    |--------------------------------------------------------------------------
    | The transition map
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{OrderStatus, OrderStatus}>
     */
    public static function legalTransitions(): array
    {
        return [
            'pending to confirmed' => [OrderStatus::Pending, OrderStatus::Confirmed],
            'pending straight to processing' => [OrderStatus::Pending, OrderStatus::Processing],
            'pending to cancelled' => [OrderStatus::Pending, OrderStatus::Cancelled],
            'confirmed to processing' => [OrderStatus::Confirmed, OrderStatus::Processing],
            'confirmed to packed' => [OrderStatus::Confirmed, OrderStatus::Packed],
            'processing to packed' => [OrderStatus::Processing, OrderStatus::Packed],
            'processing to shipped' => [OrderStatus::Processing, OrderStatus::Shipped],
            'packed to shipped' => [OrderStatus::Packed, OrderStatus::Shipped],
            'shipped to delivered' => [OrderStatus::Shipped, OrderStatus::Delivered],
            'shipped straight to returned' => [OrderStatus::Shipped, OrderStatus::Returned],
            'delivered to returned' => [OrderStatus::Delivered, OrderStatus::Returned],
            'returned to refunded' => [OrderStatus::Returned, OrderStatus::Refunded],
            'cancelled to refunded' => [OrderStatus::Cancelled, OrderStatus::Refunded],
        ];
    }

    #[Test]
    #[DataProvider('legalTransitions')]
    public function a_legal_transition_is_accepted(OrderStatus $from, OrderStatus $to): void
    {
        $order = Order::factory()->status($from)->create();

        $updated = $this->orders()->transitionTo($order, $to);

        $this->assertSame($to, $updated->status);
    }

    /**
     * @return array<string, array{OrderStatus, OrderStatus}>
     */
    public static function illegalTransitions(): array
    {
        return [
            // Backwards. The physical event already happened, and a status
            // that can retreat makes the history meaningless.
            'shipped back to processing' => [OrderStatus::Shipped, OrderStatus::Processing],
            'delivered back to shipped' => [OrderStatus::Delivered, OrderStatus::Shipped],
            'confirmed back to pending' => [OrderStatus::Confirmed, OrderStatus::Pending],

            // Cancelling a parcel that is already with a carrier. The store no
            // longer controls it; the instrument is a return.
            'shipped to cancelled' => [OrderStatus::Shipped, OrderStatus::Cancelled],
            'delivered to cancelled' => [OrderStatus::Delivered, OrderStatus::Cancelled],

            // Out of a terminal state.
            'refunded to processing' => [OrderStatus::Refunded, OrderStatus::Processing],
            'refunded to shipped' => [OrderStatus::Refunded, OrderStatus::Shipped],

            // Skipping the physical sequence entirely.
            'pending to delivered' => [OrderStatus::Pending, OrderStatus::Delivered],
            'pending to shipped' => [OrderStatus::Pending, OrderStatus::Shipped],
            'cancelled to shipped' => [OrderStatus::Cancelled, OrderStatus::Shipped],
        ];
    }

    #[Test]
    #[DataProvider('illegalTransitions')]
    public function an_illegal_transition_is_refused(OrderStatus $from, OrderStatus $to): void
    {
        $order = Order::factory()->status($from)->create();

        $this->expectException(ValidationException::class);

        $this->orders()->transitionTo($order, $to);
    }

    #[Test]
    public function a_no_op_transition_is_refused(): void
    {
        /*
         * Silently succeeding would write a history row saying the status
         * changed to what it already was, and a timeline full of those hides
         * the real events.
         */
        $order = Order::factory()->status(OrderStatus::Processing)->create();

        $this->expectException(ValidationException::class);

        $this->orders()->transitionTo($order, OrderStatus::Processing);
    }

    #[Test]
    public function delivered_is_not_terminal(): void
    {
        // Modelling it as an endpoint would make returns unrepresentable.
        $this->assertFalse(OrderStatus::Delivered->isTerminal());
        $this->assertTrue(OrderStatus::Refunded->isTerminal());
    }

    /*
    |--------------------------------------------------------------------------
    | The audit trail
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_transition_records_both_sides_of_the_change(): void
    {
        $order = Order::factory()->status(OrderStatus::Confirmed)->create();
        $admin = Admin::factory()->create(['name' => 'Warehouse Staff']);

        $this->orders()->transitionTo($order, OrderStatus::Processing, $admin, 'Picking started.');

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->getKey(),
            'from_status' => OrderStatus::Confirmed->value,
            'to_status' => OrderStatus::Processing->value,
            'admin_id' => $admin->getKey(),
            'actor_label' => 'Warehouse Staff',
            'comment' => 'Picking started.',
        ]);
    }

    #[Test]
    public function the_audit_trail_cannot_be_edited(): void
    {
        $order = Order::factory()->status(OrderStatus::Pending)->create();
        $this->orders()->transitionTo($order, OrderStatus::Confirmed);

        $entry = $order->statusHistory()->latest('id')->firstOrFail();

        $this->expectException(LogicException::class);

        $entry->forceFill(['to_status' => OrderStatus::Delivered->value])->save();
    }

    #[Test]
    public function the_audit_trail_cannot_be_deleted(): void
    {
        $order = Order::factory()->status(OrderStatus::Pending)->create();
        $this->orders()->transitionTo($order, OrderStatus::Confirmed);

        $this->expectException(LogicException::class);

        $order->statusHistory()->latest('id')->firstOrFail()->delete();
    }

    #[Test]
    public function a_status_cannot_be_written_outside_the_service(): void
    {
        /*
         * The transition map, the history row, the restock, and the event are
         * one indivisible unit of work. A bare assignment performs one fifth of
         * it, so the model refuses rather than merely documenting the rule.
         */
        $order = Order::factory()->status(OrderStatus::Pending)->create();

        $this->expectException(LogicException::class);

        $order->forceFill(['status' => OrderStatus::Delivered])->save();
    }

    #[Test]
    public function a_transition_stamps_its_lifecycle_timestamp(): void
    {
        $order = Order::factory()->status(OrderStatus::Packed)->create();

        $this->assertNull($order->shipped_at);

        $updated = $this->orders()->transitionTo($order, OrderStatus::Shipped);

        $this->assertNotNull($updated->shipped_at);
    }

    #[Test]
    public function a_transition_dispatches_its_event(): void
    {
        Event::fake([OrderStatusChanged::class]);

        $order = Order::factory()->status(OrderStatus::Confirmed)->create();

        $this->orders()->transitionTo($order, OrderStatus::Processing);

        Event::assertDispatched(
            OrderStatusChanged::class,
            fn (OrderStatusChanged $event): bool => $event->status === OrderStatus::Processing
                && $event->order->is($order),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Restocking
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function cancelling_returns_the_units_to_the_shelf(): void
    {
        [$order, $product] = $this->orderHoldingStock(stock: 7, quantity: 3);

        $this->orders()->cancel($order);

        $this->assertSame(10, (int) $product->fresh()->stock, '7 on hand + 3 returned.');

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->getKey(),
            'reason' => 'return',
            'quantity' => 3,
        ]);
    }

    #[Test]
    public function cancelling_twice_cannot_restock_twice(): void
    {
        [$order, $product] = $this->orderHoldingStock(stock: 7, quantity: 3);

        $this->orders()->cancel($order);

        // A second cancellation is an illegal transition anyway, but the flag
        // is what makes a cancel-then-refund sequence safe.
        try {
            $this->orders()->cancel($order->fresh());
        } catch (ValidationException) {
            // Expected.
        }

        $this->assertSame(10, (int) $product->fresh()->stock, 'Units must not be invented.');
        $this->assertFalse((bool) $order->fresh()->items()->first()->stock_was_reduced);
    }

    #[Test]
    public function restocking_can_be_declined(): void
    {
        /*
         * Goods damaged in the warehouse must not go back on sale. The caller
         * says so explicitly rather than the system inferring it.
         */
        [$order, $product] = $this->orderHoldingStock(stock: 7, quantity: 3);

        $this->orders()->transitionTo(
            $order,
            OrderStatus::Cancelled,
            comment: 'Damaged in the warehouse.',
            restock: false,
        );

        $this->assertSame(7, (int) $product->fresh()->stock);
    }

    #[Test]
    public function a_line_that_never_took_stock_is_not_restocked(): void
    {
        $product = Product::factory()->digital()->published()->create(['price' => 2_000]);
        $order = Order::factory()->paid()->create();

        OrderItem::factory()
            ->forProduct($product, 2)
            ->withoutStockReduction()
            ->create(['order_id' => $order->getKey()]);

        $before = (int) $product->fresh()->stock;

        $this->orders()->cancel($order->refresh());

        $this->assertSame($before, (int) $product->fresh()->stock);
    }

    #[Test]
    public function moving_between_stock_holding_states_does_not_restock(): void
    {
        // Confirmed -> Processing both hold stock, so nothing should move.
        [$order, $product] = $this->orderHoldingStock(stock: 7, quantity: 3);

        $this->orders()->transitionTo($order, OrderStatus::Processing);

        $this->assertSame(7, (int) $product->fresh()->stock);
        $this->assertTrue((bool) $order->fresh()->items()->first()->stock_was_reduced);
    }

    /*
    |--------------------------------------------------------------------------
    | Cancellation rules
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_admin_may_cancel_up_to_and_including_packed(): void
    {
        foreach ([OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::Packed] as $status) {
            $this->assertTrue(
                $status->isCancellable(),
                sprintf('%s should be cancellable — the parcel is still on the premises.', $status->value),
            );
        }

        $this->assertFalse(OrderStatus::Shipped->isCancellable());
        $this->assertFalse(OrderStatus::Delivered->isCancellable());
    }

    #[Test]
    public function a_customer_may_only_cancel_before_picking_begins(): void
    {
        // Stricter than the admin rule: past Confirmed, staff may already be
        // holding the item, and a self-service cancellation races the warehouse.
        $this->assertTrue(OrderStatus::Pending->isCustomerCancellable());
        $this->assertTrue(OrderStatus::Confirmed->isCustomerCancellable());
        $this->assertFalse(OrderStatus::Processing->isCustomerCancellable());
        $this->assertFalse(OrderStatus::Packed->isCustomerCancellable());
    }

    #[Test]
    public function a_customer_cannot_cancel_an_order_being_prepared(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->forUser($user)->status(OrderStatus::Processing)->create();

        $this->expectException(ValidationException::class);

        $this->orders()->cancel($order, $user);
    }

    #[Test]
    public function an_admin_can_cancel_an_order_being_prepared(): void
    {
        $admin = Admin::factory()->create();
        $order = Order::factory()->status(OrderStatus::Processing)->create();

        $updated = $this->orders()->cancel($order, $admin, 'Customer called.');

        $this->assertSame(OrderStatus::Cancelled, $updated->status);
        $this->assertNotNull($updated->cancelled_at);
    }

    /*
    |--------------------------------------------------------------------------
    | Payment status
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function payment_status_moves_independently_of_order_status(): void
    {
        /*
         * A cash-on-delivery order ships while payment is Pending. Collapsing
         * the two into one column would need a state per combination.
         */
        $order = Order::factory()->status(OrderStatus::Shipped)->create();

        $this->assertSame(PaymentStatus::Pending, $order->payment_status);

        $updated = $this->orders()->setPaymentStatus($order, PaymentStatus::Paid);

        $this->assertSame(PaymentStatus::Paid, $updated->payment_status);
        $this->assertSame(OrderStatus::Shipped, $updated->status, 'The order status is untouched.');
    }

    #[Test]
    public function payment_changes_are_recorded_in_their_own_stream(): void
    {
        $order = Order::factory()->status(OrderStatus::Confirmed)->create();

        $this->orders()->setPaymentStatus($order, PaymentStatus::Paid, comment: 'Bank transfer cleared.');

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->getKey(),
            'stream' => OrderStatusHistory::STREAM_PAYMENT,
            'from_status' => PaymentStatus::Pending->value,
            'to_status' => PaymentStatus::Paid->value,
        ]);
    }

    #[Test]
    public function marking_paid_confirms_an_order_still_pending(): void
    {
        $order = Order::factory()->status(OrderStatus::Pending)->create();

        $updated = $this->orders()->markPaid($order);

        $this->assertSame(PaymentStatus::Paid, $updated->payment_status);
        $this->assertSame(OrderStatus::Confirmed, $updated->status, 'Money arriving makes it ready to pick.');
    }

    #[Test]
    public function marking_paid_does_not_move_an_order_already_in_fulfilment(): void
    {
        /*
         * Recording a late bank transfer against an order already being packed
         * must not attempt an illegal backwards transition.
         */
        $order = Order::factory()->status(OrderStatus::Packed)->create();

        $updated = $this->orders()->markPaid($order);

        $this->assertSame(PaymentStatus::Paid, $updated->payment_status);
        $this->assertSame(OrderStatus::Packed, $updated->status);
    }

    #[Test]
    public function marking_paid_settles_the_pending_payment_row(): void
    {
        $order = Order::factory()->status(OrderStatus::Pending)->create();
        Payment::factory()->forOrder($order)->create();

        $this->orders()->markPaid($order);

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->getKey(),
            'status' => Payment::STATUS_PAID,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Notes and tracking
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_note_is_internal_unless_explicitly_shared(): void
    {
        $order = Order::factory()->create();
        $admin = Admin::factory()->create();

        $note = $this->orders()->addNote($order, 'Customer sounds unhappy.', $admin);

        $this->assertFalse(
            (bool) $note->is_customer_visible,
            'Exposing a note must be a deliberate act, not the result of omitting an argument.',
        );
    }

    #[Test]
    public function setting_tracking_leaves_a_customer_visible_note(): void
    {
        $order = Order::factory()->status(OrderStatus::Packed)->create();
        $admin = Admin::factory()->create();

        $updated = $this->orders()->setTracking(
            $order,
            'TRACK-12345',
            'https://carrier.test/TRACK-12345',
            actor: $admin,
        );

        $this->assertSame('TRACK-12345', $updated->tracking_number);

        $note = $order->notes()->latest('id')->firstOrFail();
        $this->assertTrue((bool) $note->is_customer_visible, 'A tracking number is for the customer.');
        $this->assertStringContainsString('TRACK-12345', $note->body);
    }

    /*
    |--------------------------------------------------------------------------
    | Revenue accounting
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function cancelled_and_refunded_orders_do_not_count_as_revenue(): void
    {
        $this->assertTrue(OrderStatus::Delivered->countsAsRevenue());
        $this->assertTrue(OrderStatus::Shipped->countsAsRevenue());

        $this->assertFalse(OrderStatus::Cancelled->countsAsRevenue());
        $this->assertFalse(OrderStatus::Refunded->countsAsRevenue());
        $this->assertFalse(OrderStatus::Returned->countsAsRevenue());
    }

    #[Test]
    public function the_revenue_scope_excludes_them(): void
    {
        Order::factory()->status(OrderStatus::Delivered)->totals(10_000)->create();
        Order::factory()->status(OrderStatus::Cancelled)->totals(5_000)->create();
        Order::factory()->status(OrderStatus::Refunded)->totals(3_000)->create();

        $this->assertSame(
            10_000,
            (int) Order::query()->revenueBearing()->sum('grand_total'),
        );
    }
}
