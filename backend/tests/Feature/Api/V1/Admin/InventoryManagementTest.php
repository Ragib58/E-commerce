<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\RoleType;
use App\Enums\StockMovementReason;
use App\Enums\TokenAbility;
use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\InventoryService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Stock adjustment, the movement ledger, and low-stock reporting.
 */
final class InventoryManagementTest extends TestCase
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
        $token = $this->superAdmin->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken;

        return $this->withToken($token);
    }

    #[Test]
    public function stock_can_be_increased_by_a_delta(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/products/{$product->id}/stock", [
                'mode' => 'delta',
                'quantity' => 15,
                'reason' => StockMovementReason::Restock->value,
                'note' => 'Supplier delivery #4471.',
            ])
            ->assertOk()
            ->assertJsonPath('data.stock', 25)
            // The response carries a complete history row, so the panel can
            // render the change it just made without refetching the ledger.
            ->assertJsonPath('data.movement.recorded_by.name', $this->superAdmin->name)
            ->assertJsonPath('data.movement.note', 'Supplier delivery #4471.');

        $this->assertSame(25, $product->refresh()->stock);
    }

    #[Test]
    public function stock_can_be_decreased_by_a_negative_delta(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/products/{$product->id}/stock", [
                'mode' => 'delta',
                'quantity' => -4,
                'reason' => StockMovementReason::Damage->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.stock', 6);
    }

    #[Test]
    public function an_absolute_adjustment_sets_the_counted_figure(): void
    {
        $product = Product::factory()->create(['stock' => 100]);

        // A stock take asserts what is on the shelf. Treating the count as a
        // delta would *add* 40 to a figure just proved wrong.
        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/products/{$product->id}/stock", [
                'mode' => 'absolute',
                'quantity' => 40,
                'reason' => StockMovementReason::Correction->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.stock', 40);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'adjustment',
            'quantity_before' => 100,
            'quantity_after' => 40,
            'quantity' => -60,
        ]);
    }

    #[Test]
    public function every_adjustment_is_journalled_with_both_sides_of_the_change(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/products/{$product->id}/stock", [
                'mode' => 'delta',
                'quantity' => 5,
                'reason' => StockMovementReason::Restock->value,
            ])
            ->assertOk();

        $movement = StockMovement::query()->where('product_id', $product->id)->latest('id')->sole();

        $this->assertSame(10, $movement->quantity_before);
        $this->assertSame(15, $movement->quantity_after);
        $this->assertSame(5, $movement->quantity);

        // The acting admin is recorded: an adjustment nobody is accountable for
        // is not an audit trail.
        $this->assertSame($this->superAdmin->id, $movement->admin_id);
    }

    #[Test]
    public function stock_cannot_go_negative_without_backorder(): void
    {
        $product = Product::factory()->create(['stock' => 3, 'allow_backorder' => false]);

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/products/{$product->id}/stock", [
                'mode' => 'delta',
                'quantity' => -10,
                'reason' => StockMovementReason::Damage->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        // The level must be untouched, and no movement recorded — a rejected
        // adjustment that still journalled would corrupt the ledger.
        $this->assertSame(3, $product->refresh()->stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    #[Test]
    public function stock_may_go_negative_when_backorder_is_allowed(): void
    {
        $product = Product::factory()->create(['stock' => 2, 'allow_backorder' => true]);

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/products/{$product->id}/stock", [
                'mode' => 'delta',
                'quantity' => -5,
                'reason' => StockMovementReason::Correction->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.stock', -3);
    }

    #[Test]
    public function a_sale_reason_cannot_be_posted_manually(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        // Sales are written by the order pipeline. A manual entry masquerading
        // as one would corrupt reconciliation against actual orders.
        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/products/{$product->id}/stock", [
                'mode' => 'delta',
                'quantity' => -1,
                'reason' => StockMovementReason::Sale->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    #[Test]
    public function a_variable_product_requires_a_variant_to_adjust(): void
    {
        $product = Product::factory()->variable()->create();
        ProductVariant::factory()->forProduct($product)->create(['stock' => 5]);

        // A variable product holds no stock of its own, so an adjustment
        // against it without naming a variant is ambiguous.
        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/products/{$product->id}/stock", [
                'mode' => 'delta',
                'quantity' => 5,
                'reason' => StockMovementReason::Restock->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variant_id');
    }

    #[Test]
    public function adjusting_a_variant_updates_the_products_rolled_up_stock(): void
    {
        $product = Product::factory()->variable()->create();
        $first = ProductVariant::factory()->forProduct($product)->create(['stock' => 5]);
        ProductVariant::factory()->forProduct($product)->create(['stock' => 7]);

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/products/{$product->id}/stock", [
                'mode' => 'delta',
                'quantity' => 10,
                'reason' => StockMovementReason::Restock->value,
                'variant_id' => $first->uuid,
            ])
            ->assertOk()
            ->assertJsonPath('data.stock', 15);

        // 15 + 7. The parent's cached figure must equal the sum of its
        // variants, or the storefront's availability would drift from reality.
        $this->assertSame(22, $product->refresh()->stock);
    }

    #[Test]
    public function a_variant_from_another_product_is_rejected(): void
    {
        $product = Product::factory()->variable()->create();
        ProductVariant::factory()->forProduct($product)->create();

        $foreign = ProductVariant::factory()->create();

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/products/{$product->id}/stock", [
                'mode' => 'delta',
                'quantity' => 1,
                'reason' => StockMovementReason::Restock->value,
                'variant_id' => $foreign->uuid,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variant_id');
    }

    #[Test]
    public function digital_products_cannot_have_their_stock_adjusted(): void
    {
        $product = Product::factory()->digital()->create();

        $this->asSuperAdmin()
            ->postJson("/api/v1/admin/products/{$product->id}/stock", [
                'mode' => 'delta',
                'quantity' => 5,
                'reason' => StockMovementReason::Restock->value,
            ])
            ->assertUnprocessable();
    }

    #[Test]
    public function the_movement_history_is_returned_newest_first(): void
    {
        $product = Product::factory()->create(['stock' => 0]);
        $inventory = app(InventoryService::class);

        $inventory->adjust($product, 10, StockMovementReason::Restock);
        $inventory->adjust($product, -2, StockMovementReason::Damage);

        $response = $this->asSuperAdmin()
            ->getJson("/api/v1/admin/products/{$product->id}/stock/history")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertSame(-2, $response->json('data.0.quantity'));
        $this->assertSame(10, $response->json('data.1.quantity'));
    }

    #[Test]
    public function a_recorded_movement_cannot_be_modified_or_deleted(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        $movement = app(InventoryService::class)->adjust($product, 5, StockMovementReason::Restock);

        // The ledger is append-only. A history that can be quietly rewritten
        // cannot settle a stock dispute, so the guarantee is enforced by the
        // model rather than merely documented.
        $movement->quantity = 9_999;

        $this->assertFalse($movement->save());
        $this->assertFalse($movement->delete());

        $this->assertDatabaseHas('stock_movements', ['id' => $movement->id, 'quantity' => 5]);
    }

    #[Test]
    public function concurrent_decrements_cannot_oversell(): void
    {
        $product = Product::factory()->create(['stock' => 1, 'allow_backorder' => false]);
        $inventory = app(InventoryService::class);

        $inventory->decrementForSale($product, 1);

        // The classic lost-update race: two requests each read 1 and each write
        // 0, promising the last unit to two customers. The locked re-read
        // inside the transaction is what makes the second attempt fail.
        $this->expectException(ValidationException::class);

        $inventory->decrementForSale($product->fresh(), 1);
    }

    #[Test]
    public function low_stock_and_out_of_stock_products_are_reported(): void
    {
        Product::factory()->lowStock(2)->create(['name' => 'Nearly Gone']);
        Product::factory()->outOfStock()->create(['name' => 'Sold Out']);
        Product::factory()->create(['name' => 'Plenty', 'stock' => 500]);

        $response = $this->asSuperAdmin()
            ->getJson('/api/v1/admin/inventory/alerts')
            ->assertOk();

        $lowStockNames = array_column($response->json('data.low_stock_products'), 'name');
        $outOfStockNames = array_column($response->json('data.out_of_stock_products'), 'name');

        $this->assertContains('Nearly Gone', $lowStockNames);
        $this->assertNotContains('Plenty', $lowStockNames);

        // Out of stock is reported separately: it is a different alert with a
        // different urgency, and folding the two together buries it.
        $this->assertContains('Sold Out', $outOfStockNames);
        $this->assertNotContains('Sold Out', $lowStockNames);
    }

    #[Test]
    public function the_inventory_summary_values_stock_at_cost(): void
    {
        Product::factory()->create(['stock' => 10, 'cost_price' => 500, 'price' => 2_000]);
        Product::factory()->create(['stock' => 5, 'cost_price' => 200, 'price' => 900]);

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/inventory/summary')
            ->assertOk()
            ->assertJsonPath('data.stock_on_hand', 15)
            // Valued at cost: this answers "what is tied up in inventory".
            // Retail valuation would overstate it by the entire margin.
            ->assertJsonPath('data.stock_value', 6_000);
    }

    #[Test]
    public function the_global_movement_ledger_can_be_filtered_to_shrinkage(): void
    {
        $product = Product::factory()->create(['stock' => 100]);
        $inventory = app(InventoryService::class);

        $inventory->adjust($product, 20, StockMovementReason::Restock);
        $inventory->adjust($product, -5, StockMovementReason::Damage);
        $inventory->adjust($product, -2, StockMovementReason::Theft);

        $response = $this->asSuperAdmin()
            ->getJson('/api/v1/admin/inventory/movements?shrinkage_only=1')
            ->assertOk();

        // Shrinkage reporting is impossible if a loss to damage is
        // indistinguishable from a decrease due to a sale.
        $this->assertCount(2, $response->json('data'));

        foreach ($response->json('data') as $movement) {
            $this->assertContains($movement['reason'], ['damage', 'theft']);
        }
    }

    #[Test]
    public function a_support_agent_cannot_adjust_stock(): void
    {
        $product = Product::factory()->create();
        $support = Admin::factory()->withRole(RoleType::SupportStaff)->create();
        $token = $support->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/admin/products/{$product->id}/stock", [
                'mode' => 'delta',
                'quantity' => 100,
                'reason' => StockMovementReason::Restock->value,
            ])
            ->assertForbidden();
    }
}
