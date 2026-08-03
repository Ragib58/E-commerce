<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AttributeResource;
use App\Http\Resources\Api\V1\BrandResource;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Http\Resources\Api\V1\ProductResource;
use App\Services\CatalogService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public storefront catalog.
 *
 * Unauthenticated and read-only. Every query here is constrained to published
 * records by CatalogService — no route parameter or filter can widen that, so a
 * draft product is unreachable from this surface even by direct slug.
 */
final class CatalogController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CatalogService $catalog,
    ) {
    }

    /**
     * GET /products
     *
     * Filtered, sorted, paginated product listing.
     */
    public function products(Request $request): JsonResponse
    {
        $products = $this->catalog->products([
            'search' => $request->string('search')->toString() ?: null,
            'category' => $request->string('category')->toString() ?: null,
            'brand' => $request->input('brand'),
            'type' => $request->string('type')->toString() ?: null,
            'min_price' => $request->input('min_price'),
            'max_price' => $request->input('max_price'),
            'attributes' => $request->input('attributes'),
            'featured' => $request->input('featured'),
            'new_arrival' => $request->input('new_arrival'),
            'best_seller' => $request->input('best_seller'),
            'in_stock' => $request->input('in_stock'),
            'sort' => $request->string('sort')->toString() ?: null,
            'per_page' => $request->input('per_page'),
        ]);

        return $this->successResponse(
            data: ProductResource::collection($products),
            message: 'Products retrieved successfully.',
            meta: [
                // Echoed back so a client can render "sorted by price" without
                // re-deriving what the server actually applied after clamping.
                'sort' => $request->string('sort')->toString()
                    ?: (string) config('catalog.listing.default_sort'),
            ],
        );
    }

    /**
     * GET /products/{slug}
     */
    public function product(string $slug): JsonResponse
    {
        $product = $this->catalog->product($slug);

        if ($product === null) {
            // Deliberately indistinguishable from a slug that never existed: a
            // different response for "exists but unpublished" would let anyone
            // enumerate the unreleased catalog.
            return $this->errorResponse(
                message: 'The requested product could not be found.',
                status: 404,
                code: 'PRODUCT_NOT_FOUND',
            );
        }

        return $this->successResponse(
            data: new ProductResource($product),
            message: 'Product retrieved successfully.',
            meta: [
                'related' => ProductResource::collection(
                    $this->catalog->relatedProducts($product),
                )->resolve(),
                'breadcrumbs' => $product->category?->ancestors()
                    ->push($product->category)
                    ->map(static fn ($category): array => [
                        'name' => $category->name,
                        'slug' => $category->slug,
                    ])
                    ->values()
                    ->all() ?? [],
            ],
        );
    }

    /**
     * GET /categories
     *
     * The published category tree for storefront navigation.
     */
    public function categories(): JsonResponse
    {
        return $this->successResponse(
            data: CategoryResource::collection($this->catalog->categoryTree()),
            message: 'Categories retrieved successfully.',
        );
    }

    /**
     * GET /categories/{slug}
     *
     * A category page: the category itself, plus its products.
     */
    public function category(Request $request, string $slug): JsonResponse
    {
        $category = $this->catalog->category($slug);

        if ($category === null) {
            return $this->errorResponse(
                message: 'The requested category could not be found.',
                status: 404,
                code: 'CATEGORY_NOT_FOUND',
            );
        }

        $products = $this->catalog->products([
            'category' => $category,
            'brand' => $request->input('brand'),
            'min_price' => $request->input('min_price'),
            'max_price' => $request->input('max_price'),
            'attributes' => $request->input('attributes'),
            'in_stock' => $request->input('in_stock'),
            'sort' => $request->string('sort')->toString() ?: null,
            'per_page' => $request->input('per_page'),
        ]);

        return $this->successResponse(
            data: ProductResource::collection($products),
            message: 'Category products retrieved successfully.',
            meta: [
                'category' => (new CategoryResource($category->load('children')))->resolve(),
                'breadcrumbs' => $category->ancestors()
                    ->push($category)
                    ->map(static fn ($item): array => [
                        'name' => $item->name,
                        'slug' => $item->slug,
                    ])
                    ->values()
                    ->all(),
                // Bounds for the price filter, scoped to this category so the
                // slider spans what is actually on the page.
                'price_range' => $this->catalog->priceRange($category),
            ],
        );
    }

    /**
     * GET /brands
     */
    public function brands(): JsonResponse
    {
        return $this->successResponse(
            data: BrandResource::collection($this->catalog->brands()),
            message: 'Brands retrieved successfully.',
        );
    }

    /**
     * GET /brands/{slug}
     */
    public function brand(Request $request, string $slug): JsonResponse
    {
        $brand = $this->catalog->brand($slug);

        if ($brand === null) {
            return $this->errorResponse(
                message: 'The requested brand could not be found.',
                status: 404,
                code: 'BRAND_NOT_FOUND',
            );
        }

        $products = $this->catalog->products([
            'brand' => [$brand->slug],
            'sort' => $request->string('sort')->toString() ?: null,
            'per_page' => $request->input('per_page'),
        ]);

        return $this->successResponse(
            data: ProductResource::collection($products),
            message: 'Brand products retrieved successfully.',
            meta: ['brand' => (new BrandResource($brand))->resolve()],
        );
    }

    /**
     * GET /catalog/filters
     *
     * Everything a filter rail needs, in one request rather than three.
     */
    public function filters(): JsonResponse
    {
        return $this->successResponse(
            data: [
                'attributes' => AttributeResource::collection(
                    $this->catalog->filterableAttributes(),
                )->resolve(),
                'brands' => BrandResource::collection($this->catalog->brands())->resolve(),
                'price_range' => $this->catalog->priceRange(),
                'sorts' => array_keys((array) config('catalog.listing.sorts', [])),
            ],
            message: 'Catalog filters retrieved successfully.',
        );
    }

    /**
     * GET /catalog/rails/{rail}
     *
     * A merchandising rail: featured, new_arrivals, or best_sellers.
     */
    public function rail(Request $request, string $rail): JsonResponse
    {
        $limit = min((int) $request->integer('limit', 12), 48);

        return $this->successResponse(
            data: ProductResource::collection($this->catalog->rail($rail, $limit)),
            message: 'Products retrieved successfully.',
        );
    }
}
