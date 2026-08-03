<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreCategoryRequest;
use App\Http\Requests\Api\V1\Admin\UpdateCategoryRequest;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Models\Category;
use App\Services\CategoryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Category administration.
 *
 * Unlike the public surface, this returns drafts and archived categories —
 * an admin cannot edit what the panel will not show them.
 */
final class CategoryController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CategoryService $categories,
    ) {
    }

    /**
     * GET /admin/categories
     *
     * Flat and paginated by default; `?tree=1` returns the hierarchy instead,
     * which the category manager and the parent picker both need.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Category::class);

        if ($request->boolean('tree')) {
            return $this->successResponse(
                data: CategoryResource::collection($this->categories->tree()),
                message: 'Category tree retrieved successfully.',
            );
        }

        $perPage = min(
            (int) $request->integer('per_page', (int) config('api.pagination.per_page')),
            (int) config('api.pagination.max_per_page'),
        );

        $categories = Category::query()
            ->withCount('products')
            ->with('parent:id,name,slug')
            ->search($request->string('search')->toString() ?: null)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('parent_id'), fn ($query) => $query->where('parent_id', $request->integer('parent_id')))
            ->orderBy('depth')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($perPage);

        return $this->successResponse(
            data: CategoryResource::collection($categories),
            message: 'Categories retrieved successfully.',
        );
    }

    /**
     * GET /admin/categories/{category}
     */
    public function show(Category $category): JsonResponse
    {
        $this->authorize('view', $category);

        return $this->successResponse(
            data: new CategoryResource($category->loadCount('products')->load('children')),
            message: 'Category retrieved successfully.',
        );
    }

    /**
     * POST /admin/categories
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->categories->create($request->payload());

        return $this->createdResponse(
            data: new CategoryResource($category),
            message: 'Category created successfully.',
        );
    }

    /**
     * PATCH /admin/categories/{category}
     */
    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $updated = $this->categories->update($category, $request->payload());

        return $this->successResponse(
            data: new CategoryResource($updated),
            message: 'Category updated successfully.',
        );
    }

    /**
     * DELETE /admin/categories/{category}
     *
     * Refuses a non-empty category unless `?cascade=1` is passed, so the
     * consequence of re-homing children and uncategorising products is always
     * an explicit choice.
     */
    public function destroy(Request $request, Category $category): JsonResponse
    {
        $this->authorize('delete', $category);

        $this->categories->delete($category, $request->boolean('cascade'));

        return $this->successResponse(message: 'Category deleted successfully.');
    }

    /**
     * PATCH /admin/categories/{category}/status
     */
    public function setStatus(Request $request, Category $category): JsonResponse
    {
        $this->authorize('update', $category);

        $validated = $request->validate([
            'status' => ['required', \Illuminate\Validation\Rule::enum(\App\Enums\ProductStatus::class)],
        ]);

        $updated = $this->categories->update($category, ['status' => $validated['status']]);

        return $this->successResponse(
            data: new CategoryResource($updated),
            message: 'Category status updated successfully.',
        );
    }

    /**
     * PUT /admin/categories/reorder
     *
     * Persists a drag-and-drop rearrangement in one request. Sending each moved
     * node separately would leave the tree in a partially reordered state if
     * one call failed.
     */
    public function reorder(Request $request): JsonResponse
    {
        $this->authorize('reorder', Category::class);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:categories,id'],
            'items.*.sort_order' => ['required', 'integer', 'min:0'],
            'items.*.parent_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
        ]);

        $this->categories->reorder($validated['items']);

        return $this->successResponse(message: 'Categories reordered successfully.');
    }
}
