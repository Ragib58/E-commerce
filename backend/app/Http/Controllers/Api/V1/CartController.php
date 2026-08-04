<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCart;
use App\Http\Requests\Api\V1\AddCartItemRequest;
use App\Http\Requests\Api\V1\UpdateCartItemRequest;
use App\Models\Cart;
use App\Services\CartService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The shopping cart.
 *
 * Every endpoint returns the *whole* recomputed cart rather than the line that
 * changed. That is deliberate: a quantity change moves the subtotal, the tax,
 * and possibly another line's availability, so returning one item would force
 * the client either to refetch or to recompute totals locally — and a client
 * that computes totals is a client whose totals can be wrong.
 *
 * Prices in the response are derived from the catalog by CartService on every
 * call. No request to this controller can name a price, because no endpoint
 * accepts one.
 */
final class CartController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CartService $carts,
    ) {
    }

    /**
     * GET /cart
     *
     * The cart, priced. Returns an empty structure rather than 404 when none
     * exists — a shopper who has never added anything has an empty cart, not a
     * missing one, and a 404 would make every client special-case first use.
     */
    public function show(Request $request): JsonResponse
    {
        $cart = $this->cart($request);

        if ($cart === null) {
            return $this->successResponse(
                data: $this->emptyCart(),
                message: 'Cart retrieved successfully.',
            );
        }

        return $this->successResponse(
            data: $this->carts->summarise($cart),
            message: 'Cart retrieved successfully.',
        );
    }

    /**
     * POST /cart/items
     */
    public function store(AddCartItemRequest $request): JsonResponse
    {
        $cart = $this->requireCart($request);

        $this->carts->add(
            cart: $cart,
            productSlugOrUuid: $request->string('product')->toString(),
            variantUuid: $request->input('variant'),
            quantity: (int) $request->integer('quantity', 1),
            options: $request->input('options'),
        );

        return $this->successResponse(
            data: $this->carts->summarise($cart),
            message: 'Added to your cart.',
            status: 201,
        );
    }

    /**
     * PATCH /cart/items/{item}
     *
     * `{item}` is the cart line's id, and CartService scopes the lookup to this
     * cart — an id from someone else's basket is rejected rather than mutated.
     */
    public function update(UpdateCartItemRequest $request, int $item): JsonResponse
    {
        $cart = $this->requireCart($request);

        $quantity = (int) $request->integer('quantity');

        $this->carts->updateQuantity($cart, $item, $quantity);

        return $this->successResponse(
            data: $this->carts->summarise($cart->refresh()),
            message: $quantity <= 0 ? 'Item removed from your cart.' : 'Cart updated.',
        );
    }

    /**
     * DELETE /cart/items/{item}
     */
    public function destroy(Request $request, int $item): JsonResponse
    {
        $cart = $this->requireCart($request);

        $this->carts->remove($cart, $item);

        return $this->successResponse(
            data: $this->carts->summarise($cart->refresh()),
            message: 'Item removed from your cart.',
        );
    }

    /**
     * DELETE /cart
     */
    public function clear(Request $request): JsonResponse
    {
        $cart = $this->requireCart($request);

        $this->carts->clear($cart);

        return $this->successResponse(
            data: $this->carts->summarise($cart->refresh()),
            message: 'Your cart has been emptied.',
        );
    }

    /**
     * POST /cart/coupon
     *
     * Stores the code. No discount is computed — see CartService::applyCoupon
     * for why a zero-discount "applied" response would be worse than an honest
     * "not yet validated".
     */
    public function applyCoupon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'coupon_code' => ['nullable', 'string', 'max:64'],
        ]);

        $cart = $this->requireCart($request);

        $this->carts->applyCoupon($cart, $validated['coupon_code'] ?? null);

        return $this->successResponse(
            data: $this->carts->summarise($cart->refresh()),
            message: 'Coupon codes are validated at checkout.',
        );
    }

    /**
     * POST /cart/merge
     *
     * Claims a guest cart for the signed-in customer. Called by the storefront
     * immediately after login and registration.
     *
     * Idempotent: calling it with no guest token, or with one already merged,
     * returns the customer's cart unchanged.
     */
    public function merge(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->errorResponse(
                message: 'Sign in before merging a guest cart.',
                status: 401,
                code: 'UNAUTHENTICATED',
            );
        }

        $cart = $this->carts->mergeGuestCart($user, $request->header(ResolveCart::HEADER));

        return $this->successResponse(
            data: $this->carts->summarise($cart),
            message: 'Your cart has been restored.',
        );
    }

    /**
     * The cart attached by the ResolveCart middleware.
     */
    private function cart(Request $request): ?Cart
    {
        $cart = $request->attributes->get(ResolveCart::ATTRIBUTE);

        return $cart instanceof Cart ? $cart : null;
    }

    /**
     * The cart, which the middleware guarantees on a mutating request.
     */
    private function requireCart(Request $request): Cart
    {
        $cart = $this->cart($request);

        if ($cart === null) {
            // Unreachable in practice: ResolveCart creates one for any unsafe
            // method. Failing loudly rather than silently creating a second
            // cart here keeps the responsibility in one place.
            abort(500, 'The cart could not be resolved for this request.');
        }

        return $cart;
    }

    /**
     * The shape of a cart that does not exist yet.
     *
     * Mirrors summarise() exactly so a client renders first use and an emptied
     * cart with the same code path.
     *
     * @return array<string, mixed>
     */
    private function emptyCart(): array
    {
        return [
            'id' => null,
            'items' => [],
            'item_count' => 0,
            'line_count' => 0,
            'totals' => [
                'subtotal' => 0,
                'discount' => 0,
                'tax' => 0,
                'shipping' => null,
                'total' => 0,
            ],
            'coupon' => [
                'code' => null,
                'applied' => false,
                'discount' => 0,
                'message' => null,
            ],
            'has_issues' => false,
        ];
    }
}
