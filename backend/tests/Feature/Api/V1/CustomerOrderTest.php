<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\TokenAbility;
use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderNote;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A customer's view of their own orders.
 *
 * Two questions dominate: can a customer reach an order that is not theirs, and
 * can they see material written for staff. Both failures are disclosures rather
 * than bugs, so the assertions are written from the attacker's side — trying
 * the access, then checking the payload for what should not be in it.
 */
final class CustomerOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();
    }

    private function asCustomer(User $user): self
    {
        return $this->withToken(
            $user->createToken('t', [TokenAbility::CustomerAccess->value])->plainTextToken,
        );
    }

    private function orderFor(User $user, OrderStatus $status = OrderStatus::Confirmed): Order
    {
        $order = Order::factory()->forUser($user)->status($status)->create();

        OrderItem::factory()->create(['order_id' => $order->getKey()]);

        return $order->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Reading your own orders
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_customer_sees_only_their_own_orders(): void
    {
        $user = User::factory()->create();

        $this->orderFor($user);
        $this->orderFor($user);
        Order::factory()->forUser(User::factory()->create())->create();
        Order::factory()->create(); // A guest order, belonging to nobody.

        $this->asCustomer($user)
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function a_customer_can_read_one_of_their_orders(): void
    {
        $user = User::factory()->create();
        $order = $this->orderFor($user);

        $this->asCustomer($user)
            ->getJson("/api/v1/orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonCount(1, 'data.items');
    }

    #[Test]
    public function a_customer_cannot_read_someone_elses_order(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $order = $this->orderFor($owner);

        $this->asCustomer($intruder)
            ->getJson("/api/v1/orders/{$order->uuid}")
            ->assertStatus(403);
    }

    #[Test]
    public function a_registered_customer_does_not_inherit_a_guest_order_sharing_their_email(): void
    {
        /*
         * Ownership is by `user_id`, never by email. Matching on address would
         * mean registering with a known email discloses that person's guest
         * order history.
         */
        $user = User::factory()->create(['email' => 'shared@example.test']);
        $guestOrder = Order::factory()->create(['customer_email' => 'shared@example.test']);

        $this->asCustomer($user)
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->asCustomer($user)
            ->getJson("/api/v1/orders/{$guestOrder->uuid}")
            ->assertStatus(403);
    }

    #[Test]
    public function orders_require_authentication(): void
    {
        $this->getJson('/api/v1/orders')->assertStatus(401);
    }

    /*
    |--------------------------------------------------------------------------
    | What a customer must not see
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_internal_note_is_never_in_a_customer_payload(): void
    {
        $user = User::factory()->create();
        $order = $this->orderFor($user);

        OrderNote::factory()->internal()->create([
            'order_id' => $order->getKey(),
            'body' => 'INTERNAL-FLAGGED-FOR-REVIEW',
        ]);
        OrderNote::factory()->customerVisible()->create([
            'order_id' => $order->getKey(),
            'body' => 'Your parcel is delayed by a day.',
        ]);

        $response = $this->asCustomer($user)->getJson("/api/v1/orders/{$order->uuid}");

        $response->assertOk()->assertJsonCount(1, 'data.notes');

        // Checked against the whole serialised body, not just the notes key —
        // a leak through some other field would be just as bad.
        $this->assertStringNotContainsString('INTERNAL-FLAGGED-FOR-REVIEW', $response->getContent());
    }

    #[Test]
    public function admin_only_fields_are_absent_from_a_customer_payload(): void
    {
        $user = User::factory()->create();
        $order = $this->orderFor($user);
        $order->forceFill([
            'admin_note' => 'INTERNAL-ADMIN-NOTE',
            'ip_address' => '203.0.113.7',
        ])->save();

        $response = $this->asCustomer($user)->getJson("/api/v1/orders/{$order->uuid}");

        $response->assertOk()
            ->assertJsonMissingPath('data.admin_note')
            ->assertJsonMissingPath('data.meta')
            ->assertJsonMissingPath('data.payments')
            ->assertJsonMissingPath('data.refunds');

        $this->assertStringNotContainsString('INTERNAL-ADMIN-NOTE', $response->getContent());
        $this->assertStringNotContainsString('203.0.113.7', $response->getContent());
    }

    #[Test]
    public function the_customer_timeline_carries_no_internal_comments(): void
    {
        $user = User::factory()->create();
        $order = $this->orderFor($user, OrderStatus::Confirmed);
        $admin = Admin::factory()->create();

        app(OrderService::class)->transitionTo(
            $order,
            OrderStatus::Processing,
            $admin,
            'INTERNAL-holding-pending-fraud-check',
        );

        $response = $this->asCustomer($user)->getJson("/api/v1/orders/{$order->uuid}");

        $response->assertOk();
        $this->assertStringNotContainsString('INTERNAL-holding', $response->getContent());

        // The shopper still sees that it moved, and when.
        $this->assertNotEmpty($response->json('data.history'));
    }

    /*
    |--------------------------------------------------------------------------
    | Tracking
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_customer_can_track_their_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->forUser($user)->shipped()->create();
        $order->forceFill(['tracking_number' => 'TRK-555'])->save();

        $this->asCustomer($user)
            ->getJson("/api/v1/orders/{$order->uuid}/track")
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Shipped->value)
            ->assertJsonPath('data.tracking.number', 'TRK-555')
            ->assertJsonPath('data.progress.step', 5)
            ->assertJsonPath('data.progress.total', 6);
    }

    #[Test]
    public function a_cancelled_order_has_no_progress_step(): void
    {
        // Rendering it as step 6 of 6 would show a cancelled order as complete.
        $user = User::factory()->create();
        $order = Order::factory()->forUser($user)->cancelled()->create();

        $this->asCustomer($user)
            ->getJson("/api/v1/orders/{$order->uuid}/track")
            ->assertOk()
            ->assertJsonPath('data.progress.step', null)
            ->assertJsonPath('data.progress.is_terminal', false);
    }

    #[Test]
    public function tracking_someone_elses_order_is_refused(): void
    {
        $order = Order::factory()->forUser(User::factory()->create())->shipped()->create();

        $this->asCustomer(User::factory()->create())
            ->getJson("/api/v1/orders/{$order->uuid}/track")
            ->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Guest lookup
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_guest_can_look_up_an_order_with_its_number_and_email(): void
    {
        $order = Order::factory()->create(['customer_email' => 'guest@example.test']);
        OrderItem::factory()->create(['order_id' => $order->getKey()]);

        $this->postJson('/api/v1/orders/lookup', [
            'order_number' => $order->order_number,
            'email' => 'guest@example.test',
        ])
            ->assertOk()
            ->assertJsonPath('data.order_number', $order->order_number);
    }

    #[Test]
    public function guest_lookup_requires_both_halves_of_the_credential(): void
    {
        $order = Order::factory()->create(['customer_email' => 'guest@example.test']);

        // Right number, wrong email.
        $this->postJson('/api/v1/orders/lookup', [
            'order_number' => $order->order_number,
            'email' => 'attacker@example.test',
        ])->assertStatus(404);
    }

    #[Test]
    public function guest_lookup_is_case_insensitive_on_the_email(): void
    {
        // A shopper retyping their address in a different case is not an
        // attacker, and refusing them would generate support tickets.
        $order = Order::factory()->create(['customer_email' => 'guest@example.test']);

        $this->postJson('/api/v1/orders/lookup', [
            'order_number' => $order->order_number,
            'email' => 'GUEST@Example.Test',
        ])->assertOk();
    }

    #[Test]
    public function a_wrong_number_and_a_wrong_email_are_indistinguishable(): void
    {
        /*
         * Distinguishing them would turn this endpoint into an oracle for
         * whether a given order number exists — which, combined with a
         * sequential number, would be an enumeration attack. The numbers are
         * random too, but the response must not undo that.
         */
        $order = Order::factory()->create(['customer_email' => 'guest@example.test']);

        $wrongEmail = $this->postJson('/api/v1/orders/lookup', [
            'order_number' => $order->order_number,
            'email' => 'nobody@example.test',
        ]);

        $wrongNumber = $this->postJson('/api/v1/orders/lookup', [
            'order_number' => 'ORD-20260101-ZZZZZZ',
            'email' => 'guest@example.test',
        ]);

        $this->assertSame($wrongEmail->status(), $wrongNumber->status());
        $this->assertSame($wrongEmail->json('message'), $wrongNumber->json('message'));
    }

    #[Test]
    public function guest_lookup_does_not_expose_internal_notes(): void
    {
        $order = Order::factory()->create(['customer_email' => 'guest@example.test']);
        $order->forceFill(['admin_note' => 'INTERNAL-GUEST-NOTE'])->save();

        OrderNote::factory()->internal()->create([
            'order_id' => $order->getKey(),
            'body' => 'INTERNAL-THREAD-NOTE',
        ]);

        $body = $this->postJson('/api/v1/orders/lookup', [
            'order_number' => $order->order_number,
            'email' => 'guest@example.test',
        ])->getContent();

        $this->assertStringNotContainsString('INTERNAL-GUEST-NOTE', $body);
        $this->assertStringNotContainsString('INTERNAL-THREAD-NOTE', $body);
    }

    /*
    |--------------------------------------------------------------------------
    | Cancellation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_customer_can_cancel_a_pending_order(): void
    {
        $user = User::factory()->create();
        $order = $this->orderFor($user, OrderStatus::Pending);

        $this->asCustomer($user)
            ->postJson("/api/v1/orders/{$order->uuid}/cancel", [
                'reason' => 'Ordered the wrong size.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value);
    }

    #[Test]
    public function a_customer_cannot_cancel_once_picking_has_begun(): void
    {
        /*
         * Past Confirmed, staff may already be holding the item — a
         * self-service cancellation would race the warehouse. The refusal
         * points to support rather than simply saying no, because the
         * shopper's request is reasonable even when the button is not.
         */
        $user = User::factory()->create();
        $order = $this->orderFor($user, OrderStatus::Processing);

        $response = $this->asCustomer($user)
            ->postJson("/api/v1/orders/{$order->uuid}/cancel");

        $response->assertStatus(403);
        $this->assertStringContainsString('Contact us', $response->json('message') ?? '');
        $this->assertSame(OrderStatus::Processing, $order->fresh()->status);
    }

    #[Test]
    public function a_customer_cannot_cancel_someone_elses_order(): void
    {
        $order = Order::factory()
            ->forUser(User::factory()->create())
            ->status(OrderStatus::Pending)
            ->create();

        // 404, not 403 — a 403 would confirm the order exists.
        $this->asCustomer(User::factory()->create())
            ->postJson("/api/v1/orders/{$order->uuid}/cancel")
            ->assertStatus(404);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    #[Test]
    public function cancelling_restocks_the_customers_units(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->published()->create(['price' => 1_000, 'stock' => 5]);

        $order = Order::factory()->forUser($user)->status(OrderStatus::Pending)->create();
        OrderItem::factory()
            ->forProduct($product, 2)
            ->create(['order_id' => $order->getKey(), 'stock_was_reduced' => true]);

        $this->asCustomer($user)
            ->postJson("/api/v1/orders/{$order->refresh()->uuid}/cancel")
            ->assertOk();

        $this->assertSame(7, (int) $product->fresh()->stock);
    }

    #[Test]
    public function a_customer_cannot_change_an_orders_status_directly(): void
    {
        // The status endpoint is staff-only; a customer's single mutating
        // action is cancellation.
        $user = User::factory()->create();
        $order = $this->orderFor($user, OrderStatus::Pending);

        $this->asCustomer($user)
            ->patchJson("/api/v1/admin/orders/{$order->uuid}/status", [
                'status' => OrderStatus::Delivered->value,
            ])
            ->assertStatus(401);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Permissions reported to the client
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_payload_reports_what_the_viewer_may_do(): void
    {
        /*
         * Decided server-side. A client deriving these itself would need the
         * transition map, and a second copy of that map eventually disagrees —
         * showing a cancel button that then fails is worse than showing none.
         */
        $user = User::factory()->create();

        $this->asCustomer($user)
            ->getJson('/api/v1/orders/'.$this->orderFor($user, OrderStatus::Pending)->uuid)
            ->assertOk()
            ->assertJsonPath('data.permissions.can_cancel', true)
            ->assertJsonPath('data.permissions.can_refund', false);

        $this->asCustomer($user)
            ->getJson('/api/v1/orders/'.$this->orderFor($user, OrderStatus::Shipped)->uuid)
            ->assertOk()
            ->assertJsonPath('data.permissions.can_cancel', false);
    }
}
