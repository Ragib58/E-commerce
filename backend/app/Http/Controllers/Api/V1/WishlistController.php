<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\WishlistItem;
use App\Services\WishlistService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Saved products.
 *
 * Authenticated only. Guests keep a wishlist in localStorage and merge it on
 * sign-in — see WishlistService for why an anonymous server-side list is not
 * worth its cost.
 */
final class WishlistController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly WishlistService $wishlist,
    ) {
    }

    /**
     * GET /wishlist
     *
     * Returns products rather than wishlist rows: the client renders the same
     * ProductCard it uses everywhere else, and a bespoke wrapper shape would
     * make that impossible without a translation step.
     */
    public function index(Request $request): JsonResponse
    {
        $items = $this->wishlist->forUser($request->user());

        return $this->successResponse(
            data: ProductResource::collection(
                $items->map(fn (WishlistItem $item) => $item->product),
            ),
            message: 'Wishlist retrieved successfully.',
            meta: ['count' => $items->count()],
        );
    }

    /**
     * POST /wishlist
     *
     * Idempotent — saving an already-saved product succeeds unchanged, so a
     * double-click is not an error the shopper has to understand.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product' => ['required', 'string', 'max:255'],
        ]);

        $this->wishlist->add($request->user(), $validated['product']);

        return $this->successResponse(
            data: ['saved' => $this->wishlist->savedIdentifiers($request->user())],
            message: 'Saved to your wishlist.',
            status: 201,
        );
    }

    /**
     * DELETE /wishlist/{product}
     */
    public function destroy(Request $request, string $product): JsonResponse
    {
        $this->wishlist->remove($request->user(), $product);

        return $this->successResponse(
            data: ['saved' => $this->wishlist->savedIdentifiers($request->user())],
            message: 'Removed from your wishlist.',
        );
    }

    /**
     * POST /wishlist/merge
     *
     * Folds a guest's localStorage wishlist into the account. Called once after
     * sign-in, alongside the cart merge.
     */
    public function merge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'products' => ['required', 'array', 'max:200'],
            'products.*' => ['string', 'max:255'],
        ]);

        $merged = $this->wishlist->merge($request->user(), $validated['products']);

        return $this->successResponse(
            data: ['saved' => $this->wishlist->savedIdentifiers($request->user())],
            message: $merged > 0
                ? 'Your saved items have been restored.'
                : 'Your wishlist is up to date.',
            meta: ['merged' => $merged],
        );
    }
}
