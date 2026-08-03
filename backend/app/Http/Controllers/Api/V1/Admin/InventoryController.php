<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdjustStockRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Http\Resources\Api\V1\ProductVariantResource;
use App\Http\Resources\Api\V1\StockMovementResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\InventoryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Stock levels, adjustments, and the movement ledger.
 *
 * Every write goes through InventoryService, which is the only code permitted
 * to assign a stock level — so no request handled here can change stock without
 * journalling it.
 */
final class InventoryController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly InventoryService $inventory,
    ) {
    }

    /**
     * POST /admin/products/{product}/stock
     *
     * Adjust stock, either by a signed delta or to an absolute counted figure.
     */
    public function adjust(AdjustStockRequest $request, Product $product): JsonResponse
    {
        $stockable = $this->resolveStockable($product, $request->validated('variant_id'));

        $quantity = (int) $request->validated('quantity');
        $note = $request->validated('note');

        $movement = $request->isAbsolute()
            ? $this->inventory->setLevel($stockable, $quantity, $request->reason(), $request->user(), $note)
            : $this->inventory->adjust($stockable, $quantity, $request->reason(), $request->user(), $note);

        return $this->successResponse(
            data: [
                // Load who recorded it and what it targeted, so the response
                // renders a complete history row without the client refetching
                // the ledger to display the change it just made.
                'movement' => (new StockMovementResource(
                    $movement->load(['admin', 'variant']),
                ))->resolve(),

                'stock' => $movement->quantity_after,
            ],
            message: 'Stock adjusted successfully.',
        );
    }

    /**
     * GET /admin/products/{product}/stock/history
     *
     * The product's movement ledger, newest first.
     */
    public function history(Request $request, Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        $perPage = min(
            (int) $request->integer('per_page', (int) config('api.pagination.per_page')),
            (int) config('api.pagination.max_per_page'),
        );

        $movements = $product->stockMovements()
            ->with(['admin:id,uuid,name', 'variant:id,uuid,name,sku'])
            ->when($request->filled('reason'), fn ($query) => $query->where('reason', $request->string('reason')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->between(
                $request->string('from')->toString() ?: null,
                $request->string('to')->toString() ?: null,
            )
            ->paginate($perPage);

        return $this->successResponse(
            data: StockMovementResource::collection($movements),
            message: 'Stock history retrieved successfully.',
        );
    }

    /**
     * GET /admin/inventory/movements
     *
     * The ledger across the whole catalog.
     */
    public function movements(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $perPage = min(
            (int) $request->integer('per_page', (int) config('api.pagination.per_page')),
            (int) config('api.pagination.max_per_page'),
        );

        $movements = StockMovement::query()
            ->with(['product:id,uuid,name,sku,slug', 'variant:id,uuid,name,sku', 'admin:id,uuid,name'])
            ->when($request->filled('reason'), fn ($query) => $query->where('reason', $request->string('reason')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->when($request->boolean('shrinkage_only'), fn ($query) => $query->shrinkage())
            ->between(
                $request->string('from')->toString() ?: null,
                $request->string('to')->toString() ?: null,
            )
            ->latest('created_at')
            ->paginate($perPage);

        return $this->successResponse(
            data: StockMovementResource::collection($movements),
            message: 'Inventory movements retrieved successfully.',
        );
    }

    /**
     * GET /admin/inventory/alerts
     *
     * Low-stock and out-of-stock items needing attention.
     *
     * Variants are reported alongside products because a variable product can
     * sit well above its threshold in total while one size is about to run out
     * — which is precisely the case a buyer needs to act on.
     */
    public function alerts(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $limit = min(
            (int) $request->integer('limit', (int) config('catalog.inventory.alert_limit')),
            200,
        );

        return $this->successResponse(
            data: [
                'low_stock_products' => ProductResource::collection(
                    $this->inventory->lowStockProducts($limit),
                )->resolve(),

                'low_stock_variants' => ProductVariantResource::collection(
                    $this->inventory->lowStockVariants($limit),
                )->resolve(),

                'out_of_stock_products' => ProductResource::collection(
                    $this->inventory->outOfStockProducts($limit),
                )->resolve(),
            ],
            message: 'Inventory alerts retrieved successfully.',
        );
    }

    /**
     * GET /admin/inventory/summary
     */
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        return $this->successResponse(
            data: $this->inventory->summary(),
            message: 'Inventory summary retrieved successfully.',
        );
    }

    /**
     * Pick the row that actually owns the stock being adjusted.
     *
     * A variable product holds no stock of its own, so an adjustment against
     * one without naming a variant is ambiguous — refused here rather than
     * guessing at a variant.
     *
     * @throws ValidationException
     */
    private function resolveStockable(Product $product, ?string $variantUuid): Product|ProductVariant
    {
        if ($variantUuid !== null) {
            $variant = ProductVariant::query()
                ->where('uuid', $variantUuid)
                ->where('product_id', $product->getKey())
                ->first();

            if ($variant === null) {
                throw ValidationException::withMessages([
                    'variant_id' => ['That variant does not belong to this product.'],
                ]);
            }

            return $variant;
        }

        if ($product->type->usesVariantStock()) {
            throw ValidationException::withMessages([
                'variant_id' => ['This is a variable product. Specify which variant to adjust.'],
            ]);
        }

        return $product;
    }
}
