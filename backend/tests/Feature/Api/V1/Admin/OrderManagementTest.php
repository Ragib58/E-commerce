<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PermissionType;
use App\Enums\RoleType;
use App\Enums\TokenAbility;
use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Order administration.
 *
 * Two things are being checked here beyond "the endpoints work": that the four
 * order permissions are actually separable — a support role that can add notes
 * must not be able to refund — and that internal material stays internal. The
 * second is the one where a mistake is a disclosure rather than a bug.
 */
final class OrderManagementTest extends TestCase
{
    use RefreshDatabase;

    private Admin $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->app->make('cache')->flush();

        $this->superAdmin = Admin::factory()->withRole(RoleType::SuperAdmin)->create();
    }

    private function asSuperAdmin(): self
    {
        return $this->withToken(
            $this->superAdmin->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken,
        );
    }

    /**
     * An admin holding exactly the named permissions and nothing else.
     */
    private function asAdminWith(PermissionType ...$permissions): self
    {
        $admin = Admin::factory()->withRole(RoleType::SupportStaff)->create();

        $admin->syncDirectPermissions(
            collect(PermissionType::cases())
                ->mapWithKeys(fn (PermissionType $p): array => [
                    $p->value => in_array($p, $permissions, strict: true),
                ])
                ->all(),
        );

        return $this->withToken(
            $admin->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken,
        );
    }

    private function orderHoldingStock(int $stock = 10, int $quantity = 2): array
    {
        $product = Product::factory()->published()->create(['price' => 5_000, 'stock' => $stock]);
        $order = Order::factory()->paid()->totals(10_000)->create();

        OrderItem::factory()
            ->forProduct($product, $quantity)
            ->create(['order_id' => $order->getKey(), 'stock_was_reduced' => true]);

        return [$order->refresh(), $product];
    }

    /*
    |--------------------------------------------------------------------------
    | Listing, searching, filtering
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function orders_can_be_listed(): void
    {
        Order::factory()->count(3)->create();

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/orders')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            // The filter vocabulary travels with the list, so the panel's
            // dropdowns come from the enum rather than being hardcoded.
            ->assertJsonPath('meta.filters.statuses.0.value', OrderStatus::Pending->value);
    }

    #[Test]
    public function orders_can_be_searched_by_order_number(): void
    {
        $target = Order::factory()->create();
        Order::factory()->count(2)->create();

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/orders?search='.$target->order_number)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_number', $target->order_number);
    }

    #[Test]
    public function orders_can_be_searched_by_customer_email(): void
    {
        Order::factory()->create(['customer_email' => 'findme@example.test']);
        Order::factory()->count(2)->create();

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/orders?search=findme@example.test')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_search_term_containing_wildcards_does_not_match_everything(): void
    {
        /*
         * An unescaped `%` would turn the lookup into a full-table wildcard
         * scan that matches every order — which is both a performance problem
         * and a way to enumerate the store's whole order book from the search
         * box.
         */
        Order::factory()->count(3)->create();

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/orders?search=%')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function orders_can_be_filtered_by_several_statuses_at_once(): void
    {
        Order::factory()->status(OrderStatus::Pending)->create();
        Order::factory()->status(OrderStatus::Processing)->create();
        Order::factory()->status(OrderStatus::Delivered)->create();

        // "Everything still to pick" is one view, not three.
        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/orders?status=pending,processing')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function an_unknown_status_filter_is_ignored_rather_than_rejected(): void
    {
        // A stale bookmark should return a sensible list, not a 422.
        Order::factory()->count(2)->create();

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/orders?status=not-a-real-status')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function orders_can_be_filtered_by_payment_status(): void
    {
        Order::factory()->paymentStatus(PaymentStatus::Paid)->create();
        Order::factory()->paymentStatus(PaymentStatus::Pending)->count(2)->create();

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/orders?payment_status=paid')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function orders_can_be_filtered_by_date_range(): void
    {
        Order::factory()->create(['placed_at' => now()->subDays(10)]);
        Order::factory()->create(['placed_at' => now()]);

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/orders?from='.now()->subDay()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function an_order_can_be_read_in_full(): void
    {
        [$order] = $this->orderHoldingStock();

        $this->asSuperAdmin()
            ->getJson("/api/v1/admin/orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonCount(1, 'data.items')
            // The integrity check is surfaced so a corrupted order is visible
            // in the panel rather than only in a report.
            ->assertJsonPath('data.totals_reconcile', true);
    }

    /*
    |--------------------------------------------------------------------------
    | Status management
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_admin_can_advance_an_order(): void
    {
        $order = Order::factory()->status(OrderStatus::Confirmed)->create();

        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/orders/{$order->uuid}/status", [
                'status' => OrderStatus::Processing->value,
                'comment' => 'Picking started.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Processing->value);

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->getKey(),
            'to_status' => OrderStatus::Processing->value,
            'comment' => 'Picking started.',
        ]);
    }

    #[Test]
    public function an_illegal_transition_is_refused_over_http(): void
    {
        $order = Order::factory()->status(OrderStatus::Delivered)->create();

        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/orders/{$order->uuid}/status", [
                'status' => OrderStatus::Processing->value,
            ])
            ->assertStatus(422);

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    #[Test]
    public function shipping_an_order_can_record_its_tracking_in_one_action(): void
    {
        $order = Order::factory()->status(OrderStatus::Packed)->create();

        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/orders/{$order->uuid}/status", [
                'status' => OrderStatus::Shipped->value,
                'tracking_number' => 'TRK-9988',
                'tracking_url' => 'https://carrier.test/TRK-9988',
            ])
            ->assertOk()
            ->assertJsonPath('data.tracking.number', 'TRK-9988');

        $this->assertNotNull($order->fresh()->shipped_at);
    }

    /*
    |--------------------------------------------------------------------------
    | Cancellation and refunds
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_admin_can_cancel_an_order_and_restock_it(): void
    {
        [$order, $product] = $this->orderHoldingStock(stock: 8, quantity: 2);

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/orders/{$order->uuid}/cancel", [
                'reason' => 'Customer changed their mind.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value);

        $this->assertSame(10, (int) $product->fresh()->stock);
    }

    #[Test]
    public function a_shipped_order_cannot_be_cancelled(): void
    {
        /*
         * 422 rather than 403, and deliberately so. A Super Admin bypasses the
         * policy — see AuthServiceProvider — so what refuses this is the
         * service's own state check, which is the layer that must hold anyway:
         * the policy decides whether the button is offered, the service decides
         * with the row locked whether the write is legal.
         *
         * The customer-facing consequence is a better message. "An order that
         * is shipped can no longer be cancelled" tells an admin why; a bare 403
         * would read as a permissions problem and send them to look for a role
         * to change.
         */
        $order = Order::factory()->shipped()->create();

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/orders/{$order->uuid}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'An order that is shipped can no longer be cancelled.');

        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);
    }

    #[Test]
    public function a_shipped_order_cannot_be_cancelled_by_a_non_super_admin_either(): void
    {
        /*
         * The same refusal one layer earlier. An ordinary admin does not bypass
         * the policy, so the block happens there — proving the rule is enforced
         * in both paths rather than only in the one a Super Admin happens to
         * take.
         */
        $order = Order::factory()->shipped()->create();

        $this->asAdminWith(PermissionType::ViewOrders, PermissionType::CancelOrders)
            ->postJson("/api/v1/admin/orders/{$order->uuid}/cancel")
            ->assertStatus(403);

        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);
    }

    #[Test]
    public function an_order_can_be_refunded_in_full(): void
    {
        $order = Order::factory()->delivered()->totals(10_000)->create();

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/orders/{$order->uuid}/refund", [
                'reason' => 'Item arrived damaged.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.refund.amount', 10_000)
            ->assertJsonPath('data.order.payment_status', PaymentStatus::Refunded->value);

        $this->assertSame(10_000, (int) $order->fresh()->refunded_total);
    }

    #[Test]
    public function a_partial_refund_leaves_the_order_partially_refunded(): void
    {
        $order = Order::factory()->delivered()->totals(10_000)->create();

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/orders/{$order->uuid}/refund", [
                'amount' => 3_000,
                'reason' => 'Shipping fee waived after a late delivery.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.order.payment_status', PaymentStatus::PartiallyRefunded->value)
            // An order with money still owed to the store is in a materially
            // different position from one that has been made whole.
            ->assertJsonPath('data.order.totals.refundable', 7_000);
    }

    #[Test]
    public function a_refund_cannot_exceed_the_remaining_balance(): void
    {
        $order = Order::factory()->delivered()->totals(10_000)->create();

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/orders/{$order->uuid}/refund", [
                'amount' => 15_000,
                'reason' => 'Typo.',
            ])
            ->assertStatus(422);

        $this->assertSame(0, (int) $order->fresh()->refunded_total);
    }

    #[Test]
    public function repeated_partial_refunds_cannot_exceed_the_total(): void
    {
        /*
         * The ceiling is checked against the running total, not against the
         * grand total alone — otherwise three £4,000 refunds against a £10,000
         * order would each pass individually.
         */
        $order = Order::factory()->delivered()->totals(10_000)->create();

        foreach ([4_000, 4_000] as $amount) {
            $this->asSuperAdmin()
                ->postJson("/api/v1/admin/orders/{$order->uuid}/refund", [
                    'amount' => $amount,
                    'reason' => 'Partial.',
                ])
                ->assertCreated();
        }

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/orders/{$order->uuid}/refund", [
                'amount' => 4_000,
                'reason' => 'One too many.',
            ])
            ->assertStatus(422);

        $this->assertSame(8_000, (int) $order->fresh()->refunded_total);
    }

    #[Test]
    public function an_unpaid_order_cannot_be_refunded(): void
    {
        /*
         * Refunding what was never captured is a cancellation, and processing
         * it as a refund would request money the store never took.
         *
         * 422 for the same reason as the cancellation case above: a Super Admin
         * bypasses the policy, so RefundService's own check is what refuses —
         * and it says what to do instead, which a 403 could not.
         */
        $order = Order::factory()->status(OrderStatus::Confirmed)->create();

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/orders/{$order->uuid}/refund", [
                'reason' => 'Nothing to refund.',
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.refund.0',
                'This order has not been paid, so there is nothing to refund. Cancel it instead.',
            );

        $this->assertSame(0, $order->refunds()->count());
    }

    #[Test]
    public function a_refund_requires_a_reason(): void
    {
        // A refund moves money out of the business; an unexplained one is the
        // entry an audit stops at.
        $order = Order::factory()->delivered()->totals(5_000)->create();

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/orders/{$order->uuid}/refund", ['amount' => 1_000])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    #[Test]
    public function a_line_refund_computes_its_own_amount(): void
    {
        /*
         * An admin chooses which lines and how many units. They do not choose
         * what a unit is worth — that comes from the order's stored price.
         */
        $product = Product::factory()->published()->create(['price' => 2_500, 'stock' => 10]);
        $order = Order::factory()->delivered()->totals(5_000)->create();

        $item = OrderItem::factory()->create([
            'order_id' => $order->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => 2,
            'unit_price' => 2_500,
            'line_total' => 5_000,
            'tax_total' => 0,
            'stock_was_reduced' => true,
        ]);

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/orders/{$order->uuid}/refund", [
                'lines' => [['order_item_id' => $item->getKey(), 'quantity' => 1]],
                'reason' => 'One unit returned.',
                'restock' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.refund.amount', 2_500);

        $this->assertSame(1, (int) $item->fresh()->refunded_quantity);
        $this->assertSame(11, (int) $product->fresh()->stock, 'One unit came back.');
    }

    #[Test]
    public function a_line_from_another_order_cannot_be_refunded(): void
    {
        $order = Order::factory()->delivered()->totals(5_000)->create();
        $otherItem = OrderItem::factory()->create();

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/orders/{$order->uuid}/refund", [
                'lines' => [['order_item_id' => $otherItem->getKey(), 'quantity' => 1]],
                'reason' => 'Wrong order.',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function refunding_can_decline_to_restock(): void
    {
        // A damaged item the store does not want back must not go on sale.
        [$order, $product] = $this->orderHoldingStock(stock: 8, quantity: 2);
        $order = Order::query()->whereKey($order->getKey())->firstOrFail();
        $order->forceFill(['payment_status' => PaymentStatus::Paid])->saveQuietly();

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/orders/{$order->uuid}/refund", [
                'reason' => 'Damaged, not returned.',
                'restock' => false,
            ])
            ->assertCreated();

        $this->assertSame(8, (int) $product->fresh()->stock);
    }

    #[Test]
    public function a_double_clicked_refund_pays_out_once(): void
    {
        $order = Order::factory()->delivered()->totals(10_000)->create();
        $headers = ['Idempotency-Key' => 'refund-attempt-01'];

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/orders/{$order->uuid}/refund", [
                'amount' => 2_000,
                'reason' => 'Goodwill.',
            ], $headers)
            ->assertCreated();

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/orders/{$order->uuid}/refund", [
                'amount' => 2_000,
                'reason' => 'Goodwill.',
            ], $headers)
            ->assertCreated();

        $this->assertSame(2_000, (int) $order->fresh()->refunded_total);
        $this->assertSame(1, $order->refunds()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Notes — the disclosure boundary
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_internal_note_is_internal_by_default(): void
    {
        $order = Order::factory()->create();

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/orders/{$order->uuid}/notes", [
                'body' => 'Flagged for review — address looks suspicious.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_customer_visible', false);
    }

    #[Test]
    public function a_note_can_be_shared_with_the_customer(): void
    {
        $order = Order::factory()->create();

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/orders/{$order->uuid}/notes", [
                'body' => 'Your parcel is delayed by one day. Sorry!',
                'is_customer_visible' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_customer_visible', true);
    }

    #[Test]
    public function an_internal_note_cannot_be_emailed_to_the_customer(): void
    {
        /*
         * Refused rather than silently ignored: "notify" on a hidden note is
         * more likely a mistake about visibility than about notification, and
         * quietly dropping the notification hides the mistake.
         */
        $order = Order::factory()->create();

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/orders/{$order->uuid}/notes", [
                'body' => 'Customer is being difficult.',
                'is_customer_visible' => false,
                'notify_customer' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'NOTE_NOT_VISIBLE');
    }

    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_invoice_renders_with_prices(): void
    {
        [$order] = $this->orderHoldingStock();

        $response = $this->asSuperAdmin()
            ->get("/api/v1/admin/orders/{$order->uuid}/invoice");

        $response->assertOk();
        $this->assertStringContainsString('Invoice', $response->getContent());
        $this->assertStringContainsString($order->order_number, $response->getContent());
    }

    #[Test]
    public function a_packing_slip_carries_no_prices(): void
    {
        /*
         * A packing slip goes in the box. A gift order that arrives with the
         * price printed on the note inside is a real complaint — so the
         * template is given no money value at all.
         */
        $product = Product::factory()->published()->create(['price' => 12_345, 'stock' => 10]);
        $order = Order::factory()->paid()->totals(12_345)->create();

        OrderItem::factory()->create([
            'order_id' => $order->getKey(),
            'product_id' => $product->getKey(),
            'product_name' => 'Gift Item',
            'quantity' => 1,
            'unit_price' => 12_345,
            'line_total' => 12_345,
        ]);

        $response = $this->asSuperAdmin()
            ->get("/api/v1/admin/orders/{$order->uuid}/packing-slip");

        $response->assertOk();
        $body = $response->getContent();

        $this->assertStringContainsString('Packing slip', $body);
        $this->assertStringContainsString('Gift Item', $body);
        $this->assertStringNotContainsString('123.45', $body);
        $this->assertStringNotContainsString('12345', $body);
    }

    #[Test]
    public function an_internal_note_never_reaches_a_printed_document(): void
    {
        [$order] = $this->orderHoldingStock();

        $order->forceFill(['admin_note' => 'INTERNAL-DO-NOT-PRINT'])->save();

        foreach (['invoice', 'packing-slip'] as $document) {
            $body = $this->asSuperAdmin()
                ->get("/api/v1/admin/orders/{$order->uuid}/{$document}")
                ->getContent();

            $this->assertStringNotContainsString('INTERNAL-DO-NOT-PRINT', $body);
        }
    }

    #[Test]
    public function an_invoice_can_be_downloaded_as_a_pdf(): void
    {
        [$order] = $this->orderHoldingStock();

        $response = $this->asSuperAdmin()
            ->get("/api/v1/admin/orders/{$order->uuid}/invoice?format=pdf");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');

        // A real PDF, not an error page with the wrong content type.
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    /*
    |--------------------------------------------------------------------------
    | Permissions are genuinely separable
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function viewing_orders_does_not_grant_updating_them(): void
    {
        $order = Order::factory()->status(OrderStatus::Confirmed)->create();

        $this->asAdminWith(PermissionType::ViewOrders)
            ->patchJson("/api/v1/admin/orders/{$order->uuid}/status", [
                'status' => OrderStatus::Processing->value,
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function updating_orders_does_not_grant_cancelling_them(): void
    {
        $order = Order::factory()->status(OrderStatus::Confirmed)->create();

        $this->asAdminWith(PermissionType::ViewOrders, PermissionType::UpdateOrders)
            ->postJson("/api/v1/admin/orders/{$order->uuid}/cancel")
            ->assertStatus(403);
    }

    #[Test]
    public function cancelling_orders_does_not_grant_refunding_them(): void
    {
        /*
         * The separation that matters most: refunding moves money out of the
         * business, and whoever answers the phone should not necessarily hold
         * it.
         */
        $order = Order::factory()->delivered()->totals(5_000)->create();

        $this->asAdminWith(PermissionType::ViewOrders, PermissionType::CancelOrders)
            ->postJson("/api/v1/admin/orders/{$order->uuid}/refund", [
                'reason' => 'Trying it on.',
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function an_admin_without_view_orders_sees_nothing(): void
    {
        Order::factory()->create();

        $this->asAdminWith(PermissionType::ViewProducts)
            ->getJson('/api/v1/admin/orders')
            ->assertStatus(403);
    }

    #[Test]
    public function a_customer_token_cannot_reach_the_admin_surface(): void
    {
        // The guards resolve different models from different tables, so this
        // fails before any permission check runs.
        $user = User::factory()->create();
        $order = Order::factory()->create();

        $this->withToken($user->createToken('t', [TokenAbility::CustomerAccess->value])->plainTextToken)
            ->getJson("/api/v1/admin/orders/{$order->uuid}")
            ->assertStatus(401);
    }

    #[Test]
    public function an_unauthenticated_request_is_rejected(): void
    {
        $order = Order::factory()->create();

        $this->getJson("/api/v1/admin/orders/{$order->uuid}")->assertStatus(401);
    }

    /*
    |--------------------------------------------------------------------------
    | Statistics
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function statistics_exclude_cancelled_and_refunded_revenue(): void
    {
        Order::factory()->status(OrderStatus::Delivered)->totals(10_000)->create();
        Order::factory()->status(OrderStatus::Cancelled)->totals(5_000)->create();

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/orders/statistics')
            ->assertOk()
            ->assertJsonPath('data.total_orders', 2)
            ->assertJsonPath('data.revenue', 10_000);
    }
}
