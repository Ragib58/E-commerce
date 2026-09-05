<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\PermissionType;
use App\Enums\RoleType;
use App\Enums\TokenAbility;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The dashboard and report endpoints.
 *
 * Two things are asserted throughout: that the figures are the ones the
 * services computed, and — more importantly — that reaching them requires the
 * reporting permissions specifically. A dashboard aggregates the whole store's
 * commercial position, so "any authenticated admin can see it" would leak
 * revenue to a support account that cannot open a single order.
 */
final class DashboardApiTest extends TestCase
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

    /*
    |--------------------------------------------------------------------------
    | Access control
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_dashboard_is_closed_to_anonymous_callers(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    }

    #[Test]
    public function the_dashboard_is_closed_to_a_customer_token(): void
    {
        $customer = User::factory()->create();

        $this->withToken(
            $customer->createToken('t', [TokenAbility::CustomerAccess->value])->plainTextToken,
        )->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    }

    #[Test]
    public function an_admin_without_reporting_permission_is_refused(): void
    {
        // Holds a real, useful permission — just not this one.
        $this->asAdminWith(PermissionType::ViewOrders)
            ->getJson('/api/v1/admin/dashboard')
            ->assertForbidden();
    }

    #[Test]
    public function view_reports_is_enough_to_read_the_dashboard(): void
    {
        $this->asAdminWith(PermissionType::ViewReports)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();
    }

    #[Test]
    public function exporting_requires_more_than_reading(): void
    {
        // Reading a summary on screen and walking out with a file of every
        // customer's email and lifetime value are different acts.
        $this->asAdminWith(PermissionType::ViewReports)
            ->get('/api/v1/admin/reports/customers/export?format=csv')
            ->assertForbidden();

        $this->asAdminWith(PermissionType::ManageReports)
            ->get('/api/v1/admin/reports/customers/export?format=csv')
            ->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Dashboard payload
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_dashboard_returns_metrics_charts_and_low_stock(): void
    {
        Order::factory()->paid()->totals(10_000)->create();
        Product::factory()->published()->create(['stock' => 2, 'low_stock_threshold' => 5]);

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.metrics.sales.period', 10_000)
            ->assertJsonPath('data.metrics.products.low_stock', 1)
            ->assertJsonCount(1, 'data.low_stock')
            ->assertJsonStructure([
                'data' => [
                    'metrics' => ['sales', 'orders', 'customers', 'products'],
                    'charts' => [
                        'sales_overview',
                        'orders_overview',
                        'revenue_by_date',
                        'top_products',
                        'top_categories',
                        'payment_methods',
                        'order_status_distribution',
                    ],
                    'low_stock',
                ],
            ]);
    }

    #[Test]
    public function money_is_returned_as_integer_minor_units(): void
    {
        Order::factory()->paid()->totals(12_345)->create();

        $response = $this->asSuperAdmin()->getJson('/api/v1/admin/dashboard/metrics')->assertOk();

        // Formatting is the client's job; a pre-formatted string would bake
        // today's currency symbol into an hour-long cache entry.
        $this->assertIsInt($response->json('data.sales.period'));
        $this->assertSame(12_345, $response->json('data.sales.period'));
    }

    #[Test]
    public function the_filters_endpoint_describes_the_panels_controls(): void
    {
        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/dashboard/filters')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['periods', 'reports', 'formats', 'all_formats'],
            ])
            ->assertJsonPath('data.periods.0.value', 'today');
    }

    /*
    |--------------------------------------------------------------------------
    | Date filtering
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_named_period_scopes_the_figures(): void
    {
        Order::factory()->paid()->totals(5_000)->create();
        Order::factory()->paid()->totals(90_000)->create([
            'created_at' => now()->subDays(60),
        ]);

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/dashboard/metrics?period=today')
            ->assertOk()
            ->assertJsonPath('data.sales.period', 5_000);
    }

    #[Test]
    public function a_custom_range_is_honoured(): void
    {
        Order::factory()->paid()->totals(7_000)->create([
            'created_at' => now()->subDays(5),
        ]);
        Order::factory()->paid()->totals(90_000)->create([
            'created_at' => now()->subDays(40),
        ]);

        $from = now()->subDays(10)->format('Y-m-d');
        $to = now()->format('Y-m-d');

        $this->asSuperAdmin()
            ->getJson("/api/v1/admin/dashboard/metrics?period=custom&from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonPath('data.sales.period', 7_000);
    }

    #[Test]
    public function a_custom_range_without_dates_is_rejected(): void
    {
        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/dashboard/metrics?period=custom')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from');
    }

    #[Test]
    public function an_inverted_custom_range_is_rejected(): void
    {
        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/dashboard/metrics?period=custom&from=2026-06-01&to=2026-01-01')
            ->assertUnprocessable();
    }

    #[Test]
    public function an_unknown_period_is_rejected(): void
    {
        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/dashboard/metrics?period=since_forever')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period');
    }

    #[Test]
    public function a_custom_range_longer_than_the_ceiling_is_rejected(): void
    {
        config()->set('reporting.limits.max_range_days', 30);

        $from = now()->subDays(400)->format('Y-m-d');
        $to = now()->format('Y-m-d');

        // A five-year range grouped by day is a query that scans most of the
        // orders table to draw a chart nobody reads.
        $this->asSuperAdmin()
            ->getJson("/api/v1/admin/dashboard/metrics?period=custom&from={$from}&to={$to}")
            ->assertUnprocessable();
    }

    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_report_index_lists_every_report_with_its_columns(): void
    {
        $response = $this->asSuperAdmin()->getJson('/api/v1/admin/reports')->assertOk();

        $this->assertCount(7, $response->json('data.reports'));
        $this->assertNotEmpty($response->json('data.reports.0.columns'));
    }

    #[Test]
    public function a_report_returns_rows_columns_and_totals(): void
    {
        Order::factory()->paid()->totals(10_000)->create(['customer_name' => 'Ada Lovelace']);

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/reports/orders')
            ->assertOk()
            ->assertJsonPath('data.report.type', 'orders')
            ->assertJsonPath('data.rows.0.customer_name', 'Ada Lovelace')
            ->assertJsonPath('data.totals.grand_total', 10_000)
            ->assertJsonPath('data.meta.total', 1);
    }

    #[Test]
    public function a_report_can_be_searched_and_filtered_over_http(): void
    {
        Order::factory()->paid()->totals(10_000)->create(['customer_name' => 'Ada Lovelace']);
        Order::factory()->paid()->totals(20_000)->create(['customer_name' => 'Grace Hopper']);

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/reports/orders?search=Grace')
            ->assertOk()
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.customer_name', 'Grace Hopper');
    }

    #[Test]
    public function an_unknown_report_is_a_404(): void
    {
        // The report name is part of the path, so an unknown one is an address
        // that does not exist rather than a bad field.
        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/reports/profit_margins')
            ->assertNotFound();
    }

    #[Test]
    public function per_page_is_capped(): void
    {
        Order::factory()->count(3)->paid()->totals(1_000)->create();

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/reports/orders?per_page=99999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    /*
    |--------------------------------------------------------------------------
    | Exports over HTTP
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_csv_export_downloads_as_a_file(): void
    {
        Order::factory()->paid()->totals(10_000)->create(['customer_name' => 'Ada Lovelace']);

        $response = $this->asSuperAdmin()
            ->get('/api/v1/admin/reports/orders/export?format=csv')
            ->assertOk();

        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'attachment; filename="orders-report_',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    #[Test]
    public function an_unknown_export_format_is_rejected(): void
    {
        $this->asSuperAdmin()
            ->get('/api/v1/admin/reports/orders/export?format=powerpoint')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('format');
    }

    #[Test]
    public function every_report_can_be_exported_as_csv(): void
    {
        Order::factory()->paid()->totals(10_000)->create();
        Product::factory()->published()->create();
        User::factory()->create();

        foreach (['sales', 'orders', 'product_sales', 'customers', 'payments', 'tax', 'inventory'] as $report) {
            $this->asSuperAdmin()
                ->get("/api/v1/admin/reports/{$report}/export?format=csv")
                ->assertOk();
        }
    }
}
