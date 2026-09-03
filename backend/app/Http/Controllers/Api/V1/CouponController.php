<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CouponService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Storefront-facing coupon discovery.
 *
 * There is deliberately only one read endpoint here. Checking whether a
 * *specific* code works belongs to the cart — `POST /cart/coupon` — because
 * applying a coupon is a cart mutation with a shopper's actual basket and
 * identity behind it, not a stateless lookup. This endpoint answers a
 * different question: "what promotions is the store currently running", for a
 * banner or a "current offers" page.
 */
final class CouponController extends Controller
{
    use ApiResponse;

    /**
     * GET /coupons
     *
     * Public coupons only — `is_public = true`. A private coupon issued to one
     * customer, or created for an affiliate to distribute, must not appear
     * here: this is the difference between "advertise this discount" and
     * "this code happens to exist", and the two are opposite intentions.
     */
    public function index(CouponService $coupons): JsonResponse
    {
        return $this->successResponse(
            data: $coupons->publicCoupons(),
            message: 'Current offers retrieved.',
        );
    }
}
