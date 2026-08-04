<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Cart;
use App\Services\CartService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the cart for a request and attaches it to the request object.
 *
 * ## Why a header rather than a cookie
 *
 * The brief asks for a "cookie/session cart" for guests, and the usual
 * implementation is a `Set-Cookie` on the API response. That does not fit this
 * architecture, and forcing it in would weaken it:
 *
 *   - The `api` middleware group is deliberately stateless. Sanctum's stateful
 *     cookie middleware is not registered, precisely so the API carries no
 *     ambient credential and therefore has no CSRF surface. A cart cookie sent
 *     automatically by the browser reintroduces exactly that surface — an
 *     attacker's page could POST to `/cart/items` and have the victim's cookie
 *     attached for free.
 *   - The storefront and the API are separate origins. A cross-site cookie now
 *     requires `SameSite=None; Secure`, which browsers increasingly partition
 *     or block outright, so the guest cart would silently stop working for a
 *     growing share of visitors.
 *
 * The token is therefore an opaque credential the *client* stores and sends
 * explicitly in `X-Cart-Token`. It is still persisted in a cookie — but a
 * first-party one written by the Next.js server on its own origin, which the
 * browser will keep. The security properties are strictly better: nothing is
 * attached automatically, so cross-site requests carry no cart identity at all.
 *
 * The cart is resolved but **not created** on read-only requests. A crawler
 * hitting `GET /cart` would otherwise insert a row per request.
 */
final class ResolveCart
{
    public const HEADER = 'X-Cart-Token';

    /** Request attribute the controller reads. */
    public const ATTRIBUTE = 'cart';

    public function __construct(
        private readonly CartService $carts,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);

        /*
         * A cart row is created only for a request that intends to change one.
         * `create` on a GET would mean every anonymous page view — including
         * every crawler hit — inserts a cart that is never used again.
         */
        $cart = $this->carts->resolve(
            user: $request->user(),
            token: $token,
            create: ! $request->isMethodSafe(),
        );

        $request->attributes->set(self::ATTRIBUTE, $cart);

        $response = $next($request);

        /*
         * Echo the token back so a client that has just been given a new guest
         * cart knows what to store. Only for guest carts: a signed-in
         * customer's cart is found by user id and has no token, and emitting
         * one would hand out a credential that outlives the session.
         */
        if ($cart instanceof Cart && $cart->user_id === null && $cart->token !== null) {
            $response->headers->set(self::HEADER, $cart->token);
        }

        return $response;
    }

    /**
     * Read the cart token from the request.
     *
     * Validated for shape before it reaches a query. The value is a bearer
     * credential arriving from a client, and a length/charset check here means
     * a malformed one is rejected outright rather than becoming a wildcard
     * lookup against an indexed column.
     */
    private function extractToken(Request $request): ?string
    {
        $token = $request->header(self::HEADER);

        if (! is_string($token) || $token === '') {
            return null;
        }

        return preg_match('/^[a-f0-9]{64}$/', $token) === 1 ? $token : null;
    }
}
