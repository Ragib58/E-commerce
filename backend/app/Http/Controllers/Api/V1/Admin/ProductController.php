<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreProductRequest;
use App\Http\Requests\Api\V1\Admin\UpdateProductRequest;
use App\Http\Resources\Api\V1\ProductMediaResource;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Services\MediaService;
use App\Services\ProductService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Product administration.
 *
 * Serves drafts and archived products, and — because an authenticated admin
 * guard resolves — ProductResource includes cost price and exact stock, neither
 * of which appears on the public surface.
 */
final class ProductController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProductService $products,
    ) {
    }

    /**
     * GET /admin/products
     *
     * The catalog table: search, filter, sort, paginate.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $perPage = min(
            (int) $request->integer('per_page', (int) config('api.pagination.per_page')),
            (int) config('api.pagination.max_per_page'),
        );

        $products = Product::query()
            ->withListingRelations()
            ->search($request->string('search')->toString() ?: null)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->integer('category_id')))
            ->when($request->filled('brand_id'), fn ($query) => $query->where('brand_id', $request->integer('brand_id')))
            ->when($request->boolean('low_stock'), fn ($query) => $query->lowStock())
            ->when($request->boolean('out_of_stock'), fn ($query) => $query->outOfStock())
            ->when($request->has('is_featured'), fn ($query) => $query->where('is_featured', $request->boolean('is_featured')))

            // Soft-deleted products are hidden unless asked for, so the default
            // table matches what the storefront could serve.
            ->when($request->boolean('trashed'), fn ($query) => $query->onlyTrashed())

            ->orderBy(
                $this->resolveSortColumn($request->string('sort_by')->toString()),
                $request->string('sort_dir')->toString() === 'asc' ? 'asc' : 'desc',
            )
            ->paginate($perPage);

        return $this->successResponse(
            data: ProductResource::collection($products),
            message: 'Products retrieved successfully.',
        );
    }

    /**
     * GET /admin/products/{product}
     */
    public function show(Product $product): JsonResponse
    {
        $this->authorize('view', $product);

        return $this->successResponse(
            // `variants`, not `activeVariants`: the panel must show an
            // inactive variant in order to re-enable it.
            data: new ProductResource($product->load([
                'category',
                'brand',
                'media',
                'variants.attributeValues.attribute',
                'variants.product',
            ])),
            message: 'Product retrieved successfully.',
        );
    }

    /**
     * POST /admin/products
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->products->create($request->payload(), $request->user());

        return $this->createdResponse(
            data: new ProductResource($product->load(['category', 'brand'])),
            message: 'Product created successfully.',
        );
    }

    /**
     * PATCH /admin/products/{product}
     */
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $updated = $this->products->update($product, $request->payload(), $request->user());

        return $this->successResponse(
            data: new ProductResource($updated->load(['category', 'brand', 'media'])),
            message: 'Product updated successfully.',
        );
    }

    /**
     * DELETE /admin/products/{product}
     */
    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        $this->products->delete($product);

        return $this->successResponse(message: 'Product deleted successfully.');
    }

    /**
     * POST /admin/products/{trashedProduct}/restore
     *
     * Resolved by hand rather than by route-model binding: the registered
     * binding excludes soft-deleted rows, which are precisely the ones this
     * route exists to recover.
     */
    public function restore(string $trashedProduct): JsonResponse
    {
        $product = Product::withTrashed()
            ->where(
                ctype_digit($trashedProduct) ? 'id' : 'uuid',
                $trashedProduct,
            )
            ->firstOrFail();

        $this->authorize('restore', $product);

        return $this->successResponse(
            data: new ProductResource($this->products->restore($product)),
            message: 'Product restored successfully.',
        );
    }

    /**
     * PATCH /admin/products/{product}/status
     *
     * The list view's status toggle.
     */
    public function setStatus(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(ProductStatus::class)],
        ]);

        $updated = $this->products->setStatus($product, ProductStatus::from($validated['status']));

        return $this->successResponse(
            data: new ProductResource($updated),
            message: 'Product status updated successfully.',
        );
    }

    /**
     * POST /admin/products/bulk
     *
     * Apply one action to a selection from the table.
     */
    public function bulk(Request $request): JsonResponse
    {
        $this->authorize('update', Product::class);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],

            // UUIDs, matching what ProductResource exposes. The integer key is
            // never published, so a client has no way to send one.
            'ids.*' => ['string', 'exists:products,uuid'],

            'action' => ['required', Rule::in(['publish', 'draft', 'archive', 'feature', 'unfeature', 'delete'])],
        ]);

        // Bulk delete is gated on the delete permission, not merely on update —
        // otherwise it would be a way around the narrower authorization that
        // the single-product route enforces.
        if ($validated['action'] === 'delete') {
            $this->authorize('delete', Product::class);
        }

        // Resolved here rather than in the service, which works in primary
        // keys — the uuid is a transport concern of this API surface.
        $ids = Product::query()
            ->whereIn('uuid', $validated['ids'])
            ->pluck('id')
            ->all();

        $affected = $this->products->bulkAction($ids, $validated['action']);

        return $this->successResponse(
            data: ['affected' => $affected],
            message: "{$affected} product(s) updated successfully.",
        );
    }

    /**
     * POST /admin/products/{product}/media
     */
    public function uploadMedia(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $request->validate([
            'image' => MediaService::imageRules(required: true),
            'alt_text' => ['nullable', 'string', 'max:255'],
            'is_thumbnail' => ['sometimes', 'boolean'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
        ]);

        $media = $this->products->addMedia(
            $product,
            $request->file('image'),
            $request->string('alt_text')->toString() ?: null,
            $request->boolean('is_thumbnail'),
            $request->input('variant_id') !== null ? (int) $request->input('variant_id') : null,
        );

        return $this->createdResponse(
            data: new ProductMediaResource($media),
            message: 'Image uploaded successfully.',
        );
    }

    /**
     * DELETE /admin/products/{product}/media/{media}
     */
    public function destroyMedia(Product $product, ProductMedia $media): JsonResponse
    {
        $this->authorize('update', $product);

        // Route-model binding resolves the media independently of the product,
        // so without this check a valid media id from *another* product would
        // be deleted through this product's route.
        if ((int) $media->product_id !== (int) $product->getKey()) {
            return $this->errorResponse(
                message: 'That image does not belong to this product.',
                status: 404,
            );
        }

        $this->products->deleteMedia($media);

        return $this->successResponse(message: 'Image deleted successfully.');
    }

    /**
     * PATCH /admin/products/{product}/media/{media}/thumbnail
     */
    public function setThumbnail(Product $product, ProductMedia $media): JsonResponse
    {
        $this->authorize('update', $product);

        $this->products->setThumbnail($product, $media);

        return $this->successResponse(message: 'Thumbnail updated successfully.');
    }

    /**
     * PUT /admin/products/{product}/media/reorder
     */
    public function reorderMedia(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:product_media,id'],
            'items.*.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $this->products->reorderMedia($product, $validated['items']);

        return $this->successResponse(message: 'Gallery reordered successfully.');
    }

    /**
     * Map a client-supplied sort key to a real column.
     *
     * An allowlist: interpolating a request value into an ORDER BY is an
     * injection vector, and every column here is indexed.
     */
    private function resolveSortColumn(string $sortBy): string
    {
        return match ($sortBy) {
            'name' => 'name',
            'price' => 'price',
            'stock' => 'stock',
            'sku' => 'sku',
            'status' => 'status',
            'updated_at' => 'updated_at',
            default => 'created_at',
        };
    }
}
