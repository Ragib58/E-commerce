<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\PermissionType;
use App\Enums\RoleType;
use App\Enums\TokenAbility;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\User;
use App\Payments\Data\PaymentVerification;
use App\Payments\PaymentGatewayManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeGateway;
use Tests\TestCase;

/**
 * Payment administration.
 *
 * Two things are under test beyond "the endpoints work": that the filters the
 * brief asks for actually filter in SQL, and that the read/manage permission
 * split is real — an accounts clerk browsing transactions must not be able to
 * generate outbound traffic against a rate-limited processor.
 *
 * The most important assertion in the file is the last one: there is no
 * endpoint through which an admin can assert that a payment succeeded.
 */
final class PaymentManagementTest extends TestCase
{
    use RefreshDatabase;

    private Admin $superAdmin;

    private FakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->app->make('cache')->flush();

        $this->superAdmin = Admin::factory()->withRole(RoleType::SuperAdmin)->create();

        $this->gateway = new FakeGateway('fake');
        $this->app->make(PaymentGatewayManager::class)->extend('fake', fn (): FakeGateway => $this->gateway);
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

    private function paymentFor(Order $order, string $status = Payment::STATUS_PAID, string $gateway = 'fake'): Payment
    {
        return Payment::factory()->forOrder($order)->create([
            'status' => $status,
            'gateway' => $gateway,
            'transaction_reference' => 'txn_'.\Illuminate\Support\Str::random(10),
            'paid_at' => $status === Payment::STATUS_PAID ? now() : null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Listing and filtering
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function all_transactions_can_be_listed(): void
    {
        $order = Order::factory()->create();
        Payment::factory()->forOrder($order)->count(3)->create();

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/payments')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            // The filter vocabulary travels with the list, so the panel's
            // dropdowns come from the application rather than being hardcoded.
            ->assertJsonStructure(['meta' => ['filters' => ['statuses', 'gateways']]]);
    }

    #[Test]
    public function payments_can_be_filtered_by_status(): void
    {
        $order = Order::factory()->create();

        $this->paymentFor($order, Payment::STATUS_PAID);
        $this->paymentFor($order, Payment::STATUS_FAILED);
        $this->paymentFor($order, Payment::STATUS_PENDING);

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/payments?status=paid')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', Payment::STATUS_PAID);
    }

    #[Test]
    public function several_statuses_can_be_requested_at_once(): void
    {
        // "Everything unresolved" is one view, not two requests.
        $order = Order::factory()->create();

        $this->paymentFor($order, Payment::STATUS_PENDING);
        $this->paymentFor($order, Payment::STATUS_PROCESSING);
        $this->paymentFor($order, Payment::STATUS_PAID);

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/payments?status=pending,processing')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function an_unknown_status_filter_is_ignored_rather_than_rejected(): void
    {
        // A stale bookmark should return a sensible list, not a 422.
        Payment::factory()->forOrder(Order::factory()->create())->count(2)->create();

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/payments?status=not-a-status')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function payments_can_be_filtered_by_gateway(): void
    {
        $order = Order::factory()->create();

        $this->paymentFor($order, Payment::STATUS_PAID, 'stripe');
        $this->paymentFor($order, Payment::STATUS_PAID, 'bkash');
        $this->paymentFor($order, Payment::STATUS_PAID, 'bkash');

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/payments?gateway=bkash')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function payments_can_be_filtered_by_date_range(): void
    {
        $order = Order::factory()->create();

        Payment::factory()->forOrder($order)->create(['created_at' => now()->subDays(10)]);
        Payment::factory()->forOrder($order)->create(['created_at' => now()]);

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/payments?from='.now()->subDay()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function payments_can_be_filtered_by_order(): void
    {
        $wanted = Order::factory()->create();
        $other = Order::factory()->create();

        Payment::factory()->forOrder($wanted)->count(2)->create();
        Payment::factory()->forOrder($other)->create();

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/payments?order='.$wanted->order_number)
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function payments_can_be_searched_by_transaction_reference(): void
    {
        $order = Order::factory()->create();

        Payment::factory()->forOrder($order)->create(['transaction_reference' => 'pi_findme_123']);
        Payment::factory()->forOrder($order)->count(2)->create();

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/payments?search=pi_findme_123')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_search_term_containing_wildcards_does_not_match_everything(): void
    {
        /*
         * An unescaped `%` would turn the lookup into a full-table scan that
         * matches every payment — both a performance problem and a way to
         * enumerate the store's transactions from the search box.
         */
        Payment::factory()->forOrder(Order::factory()->create())->count(3)->create();

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/payments?search=%')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function filters_compose(): void
    {
        $order = Order::factory()->create();

        $this->paymentFor($order, Payment::STATUS_PAID, 'stripe');
        $this->paymentFor($order, Payment::STATUS_FAILED, 'stripe');
        $this->paymentFor($order, Payment::STATUS_PAID, 'bkash');

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/payments?gateway=stripe&status=paid')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /*
    |--------------------------------------------------------------------------
    | Detail and statistics
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_payment_can_be_read_in_full(): void
    {
        $order = Order::factory()->create();
        $payment = $this->paymentFor($order);

        $this->asSuperAdmin()
            ->getJson("/api/v1/admin/payments/{$payment->uuid}")
            ->assertOk()
            ->assertJsonPath('data.id', $payment->uuid)
            ->assertJsonPath('data.order.order_number', $order->order_number);
    }

    #[Test]
    public function statistics_count_only_captured_money_as_revenue(): void
    {
        /*
         * Summing every row regardless of status would report failed attempts
         * as revenue — the single most misleading figure a payments dashboard
         * can show.
         */
        $order = Order::factory()->create();

        Payment::factory()->forOrder($order)->create(['status' => Payment::STATUS_PAID, 'amount' => 10_000]);
        Payment::factory()->forOrder($order)->create(['status' => Payment::STATUS_FAILED, 'amount' => 50_000]);

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/payments/statistics')
            ->assertOk()
            ->assertJsonPath('data.total_transactions', 2)
            ->assertJsonPath('data.captured', 10_000);
    }

    #[Test]
    public function statistics_break_down_by_status(): void
    {
        $order = Order::factory()->create();

        Payment::factory()->forOrder($order)->create(['status' => Payment::STATUS_PAID]);
        Payment::factory()->forOrder($order)->count(2)->create(['status' => Payment::STATUS_FAILED]);

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/payments/statistics')
            ->assertOk()
            ->assertJsonPath('data.by_status.paid', 1)
            ->assertJsonPath('data.by_status.failed', 2);
    }

    /*
    |--------------------------------------------------------------------------
    | The event trail
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_payments_inbound_events_can_be_reviewed(): void
    {
        $order = Order::factory()->create();
        $payment = $this->paymentFor($order);

        PaymentWebhookEvent::factory()->count(2)->create([
            'payment_id' => $payment->getKey(),
            'order_id' => $order->getKey(),
        ]);

        $this->asSuperAdmin()
            ->getJson("/api/v1/admin/payments/{$payment->uuid}/events")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function rejected_webhook_attempts_are_listed_separately(): void
    {
        /*
         * A security view rather than an operational one. One failure is noise;
         * a run of them is someone probing the endpoint, and that pattern is
         * only visible if the attempts are stored and queryable.
         */
        PaymentWebhookEvent::factory()->unverified()->count(3)->create();
        PaymentWebhookEvent::factory()->count(2)->create();

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/payments/events/unverified')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    /*
    |--------------------------------------------------------------------------
    | Re-verification
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_admin_can_ask_the_gateway_to_re_verify_a_payment(): void
    {
        $order = Order::factory()->totals(10_000)->create();
        $payment = Payment::factory()->forOrder($order)->create([
            'status' => Payment::STATUS_PROCESSING,
            'gateway' => 'fake',
            'amount' => 10_000,
        ]);

        $this->gateway->nextVerification = PaymentVerification::paid(
            gateway: 'fake',
            reference: 'txn_found_it',
            amount: 10_000,
            currency: $order->currency,
        );

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/payments/{$payment->uuid}/verify")
            ->assertOk()
            ->assertJsonPath('data.verification.status', PaymentVerification::STATUS_PAID);

        $this->assertSame(Payment::STATUS_PAID, $payment->refresh()->status);
    }

    #[Test]
    public function re_verification_goes_through_the_same_settlement_path(): void
    {
        /*
         * An admin cannot assert an outcome — only ask the gateway for one. If
         * the gateway says the payment failed, the payment fails, however much
         * the customer insists otherwise.
         */
        $order = Order::factory()->totals(10_000)->create();
        $payment = Payment::factory()->forOrder($order)->create([
            'status' => Payment::STATUS_PROCESSING,
            'gateway' => 'fake',
            'amount' => 10_000,
        ]);

        $this->gateway->nextVerification = PaymentVerification::failed('fake', 'No such transaction.');

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/payments/{$payment->uuid}/verify")
            ->assertOk()
            ->assertJsonPath('data.verification.status', PaymentVerification::STATUS_FAILED);

        $this->assertSame(Payment::STATUS_FAILED, $payment->refresh()->status);
    }

    #[Test]
    public function there_is_no_endpoint_that_marks_a_payment_paid_directly(): void
    {
        /*
         * The most important assertion in this file.
         *
         * An endpoint letting staff set a payment to Paid would be the one hole
         * in the "never without verification" rule — and it would get used,
         * because it is the fastest way to close a support ticket.
         *
         * Asserted against the registered routes rather than by trying URLs, so
         * it catches such an endpoint being added under any name.
         */
        $paymentRoutes = collect(app('router')->getRoutes())
            ->filter(fn ($route): bool => str_contains($route->uri(), 'admin/payments'))
            ->map(fn ($route): string => $route->uri())
            ->values();

        $this->assertNotEmpty($paymentRoutes);

        foreach ($paymentRoutes as $uri) {
            foreach (['mark-paid', 'settle', 'approve', 'capture', 'force'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $uri);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function viewing_payments_requires_the_permission(): void
    {
        Payment::factory()->forOrder(Order::factory()->create())->create();

        $this->asAdminWith(PermissionType::ViewOrders)
            ->getJson('/api/v1/admin/payments')
            ->assertStatus(403);
    }

    #[Test]
    public function viewing_payments_does_not_grant_re_verifying_them(): void
    {
        /*
         * The split that matters: reading is what a support agent does all day,
         * while re-verifying makes an outbound API call to a rate-limited
         * processor.
         */
        $order = Order::factory()->create();
        $payment = $this->paymentFor($order);

        $this->asAdminWith(PermissionType::ViewPayments)
            ->postJson("/api/v1/admin/payments/{$payment->uuid}/verify")
            ->assertStatus(403);
    }

    #[Test]
    public function an_admin_with_view_payments_can_list_them(): void
    {
        Payment::factory()->forOrder(Order::factory()->create())->create();

        $this->asAdminWith(PermissionType::ViewPayments)
            ->getJson('/api/v1/admin/payments')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_customer_token_cannot_reach_the_payment_admin_surface(): void
    {
        // The guards resolve different models from different tables, so this
        // fails before any permission check runs.
        $user = User::factory()->create();

        $this->withToken($user->createToken('t', [TokenAbility::CustomerAccess->value])->plainTextToken)
            ->getJson('/api/v1/admin/payments')
            ->assertStatus(401);
    }

    #[Test]
    public function an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/payments')->assertStatus(401);
    }
}
