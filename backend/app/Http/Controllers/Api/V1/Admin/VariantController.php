<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreVariantRequest;
use App\Http\Requests\Api\V1\Admin\UpdateVariantRequest;
use App\Http\Resources\Api\V1\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\VariantService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Variant administration, nested under a product.
 *
 * Authorization is on the *product* throughout: a variant has no independent
 * existence, and someone who may edit the product may edit its variants.
 */
final class VariantController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly VariantService $variants,
    ) {
    }

    /**
     * GET /admin/products/{product}/variants
     */
    public function index(Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        return $this->successResponse(
            data: ProductVariantResource::collection(
                $product->variants()->with(['attributeValues.attribute', 'product'])->get(),
            ),
            message: 'Variants retrieved successfully.',
        );
    }

    /**
     * POST /admin/products/{product}/variants
     */
    public function store(StoreVariantRequest $request, Product $product): JsonResponse
    {
        $variant = $this->variants->create($product, $request->payload(), $request->user());

        return $this->createdResponse(
            data: new ProductVariantResource($variant),
            message: 'Variant created successfully.',
        );
    }

    /**
     * PATCH /admin/variants/{variant}
     */
    public function update(UpdateVariantRequest $request, ProductVariant $variant): JsonResponse
    {
        $updated = $this->variants->update($variant, $request->payload(), $request->user());

        return $this->successResponse(
            data: new ProductVariantResource($updated),
            message: 'Variant updated successfully.',
        );
    }

    /**
     * DELETE /admin/variants/{variant}
     */
    public function destroy(ProductVariant $variant): JsonResponse
    {
        $this->authorize('update', $variant->product);

        $this->variants->delete($variant);

        return $this->successResponse(message: 'Variant deleted successfully.');
    }

    /**
     * POST /admin/products/{product}/variants/generate
     *
     * Build the whole option matrix at once.
     *
     * Creating a 4x3 grid by hand is twelve passes through a form; this takes
     * the cartesian product of the selected values and skips combinations that
     * already exist, so it is safe to re-run after adding a colour.
     */
    public function generate(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $validated = $request->validate([
            // Value ids grouped by attribute: [[1,2,3], [7,8]].
            'attributes' => ['required', 'array', 'min:1'],
            'attributes.*' => ['required', 'array', 'min:1'],
            'attributes.*.*' => ['integer', 'exists:attribute_values,id'],

            'defaults' => ['sometimes', 'array'],
            'defaults.price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'defaults.stock' => ['sometimes', 'integer', 'min:0'],
            'defaults.low_stock_threshold' => ['sometimes', 'integer', 'min:0'],
        ]);

        $created = $this->variants->generateMatrix(
            $product,
            array_values($validated['attributes']),
            $validated['defaults'] ?? [],
            $request->user(),
        );

        return $this->createdResponse(
            data: ProductVariantResource::collection(collect($created)),
            message: sprintf(
                '%d variant(s) generated. Existing combinations were left untouched.',
                count($created),
            ),
        );
    }
}
