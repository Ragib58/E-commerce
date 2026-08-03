<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreBrandRequest;
use App\Http\Requests\Api\V1\Admin\UpdateBrandRequest;
use App\Http\Resources\Api\V1\BrandResource;
use App\Models\Brand;
use App\Services\BrandService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Brand administration.
 */
final class BrandController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BrandService $brands,
    ) {
    }

    /**
     * GET /admin/brands
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Brand::class);

        $perPage = min(
            (int) $request->integer('per_page', (int) config('api.pagination.per_page')),
            (int) config('api.pagination.max_per_page'),
        );

        $brands = Brand::query()
            ->withCount('products')
            ->search($request->string('search')->toString() ?: null)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($perPage);

        return $this->successResponse(
            data: BrandResource::collection($brands),
            message: 'Brands retrieved successfully.',
        );
    }

    /**
     * GET /admin/brands/{brand}
     */
    public function show(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        return $this->successResponse(
            data: new BrandResource($brand->loadCount('products')),
            message: 'Brand retrieved successfully.',
        );
    }

    /**
     * POST /admin/brands
     */
    public function store(StoreBrandRequest $request): JsonResponse
    {
        $brand = $this->brands->create($request->payload());

        return $this->createdResponse(
            data: new BrandResource($brand),
            message: 'Brand created successfully.',
        );
    }

    /**
     * PATCH /admin/brands/{brand}
     */
    public function update(UpdateBrandRequest $request, Brand $brand): JsonResponse
    {
        $updated = $this->brands->update($brand, $request->payload());

        return $this->successResponse(
            data: new BrandResource($updated),
            message: 'Brand updated successfully.',
        );
    }

    /**
     * DELETE /admin/brands/{brand}
     */
    public function destroy(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('delete', $brand);

        $this->brands->delete($brand, $request->boolean('cascade'));

        return $this->successResponse(message: 'Brand deleted successfully.');
    }

    /**
     * PATCH /admin/brands/{brand}/status
     */
    public function setStatus(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(ProductStatus::class)],
        ]);

        $updated = $this->brands->update($brand, ['status' => $validated['status']]);

        return $this->successResponse(
            data: new BrandResource($updated),
            message: 'Brand status updated successfully.',
        );
    }
}
