<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreCouponRequest;
use App\Http\Requests\Api\V1\Admin\UpdateCouponRequest;
use App\Http\Resources\Api\V1\CouponResource;
use App\Http\Resources\Api\V1\CouponUsageResource;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Coupon administration.
 *
 * Every field this controller writes is a column CouponService reads back at
 * redemption — see Coupon's migration. Nothing here computes or previews a
 * discount; that is deliberately CouponService's job alone, reached from the
 * storefront through the cart and checkout endpoints, so the arithmetic exists
 * in exactly one place regardless of who is asking.
 */
final class CouponController extends Controller
{
    use ApiResponse;

    /**
     * GET /admin/coupons
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(
            (int) $request->integer('per_page', (int) config('api.pagination.per_page')),
            (int) config('api.pagination.max_per_page'),
        );

        $coupons = Coupon::query()
            ->when(
                $request->filled('search'),
                fn ($query) => $query->where(fn ($inner) => $inner
                    ->where('code', 'like', '%'.$request->string('search')->toString().'%')
                    ->orWhere('name', 'like', '%'.$request->string('search')->toString().'%')),
            )
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->latest('id')
            ->paginate($perPage);

        return $this->successResponse(
            data: CouponResource::collection($coupons),
            message: 'Coupons retrieved.',
        );
    }

    /**
     * GET /admin/coupons/{coupon}
     */
    public function show(Coupon $coupon): JsonResponse
    {
        $coupon->load(['products', 'categories', 'users', 'creator']);
        $coupon->loadCount('users');

        return $this->successResponse(
            data: new CouponResource($coupon),
            message: 'Coupon retrieved.',
        );
    }

    /**
     * POST /admin/coupons
     */
    public function store(StoreCouponRequest $request): JsonResponse
    {
        $coupon = Coupon::query()->create(array_merge(
            $request->payload(),
            ['uuid' => (string) Str::uuid()],
        ));

        $this->syncScope($coupon, $request);

        return $this->createdResponse(
            data: new CouponResource($coupon->fresh(['products', 'categories', 'users'])),
            message: 'Coupon created.',
        );
    }

    /**
     * PATCH /admin/coupons/{coupon}
     */
    public function update(UpdateCouponRequest $request, Coupon $coupon): JsonResponse
    {
        $coupon->update($request->payload());

        $this->syncScope($coupon, $request);

        return $this->successResponse(
            data: new CouponResource($coupon->fresh(['products', 'categories', 'users'])),
            message: 'Coupon updated.',
        );
    }

    /**
     * DELETE /admin/coupons/{coupon}
     *
     * Soft-deleted rather than truly removed — Coupon uses SoftDeletes — so a
     * coupon with redemption history behind it stays reachable for reporting
     * even after it is retired from checkout. `restrictOnDelete` on
     * `coupon_usages.coupon_id` means the row would refuse a hard delete
     * anyway if one were attempted; the soft delete is what makes "retire
     * this coupon" the actual operation an admin performs.
     */
    public function destroy(Coupon $coupon): JsonResponse
    {
        $coupon->delete();

        return $this->successResponse(message: 'Coupon deleted.');
    }

    /**
     * GET /admin/coupons/{coupon}/usages
     *
     * The redemption ledger for one coupon — which orders used it, for how
     * much, and by whom. What a finance review or a "did this promotion
     * actually work" question reads.
     */
    public function usages(Coupon $coupon): JsonResponse
    {
        $usages = CouponUsage::query()
            ->where('coupon_id', $coupon->getKey())
            ->with(['user', 'order'])
            ->latest('id')
            ->paginate((int) config('api.pagination.per_page'));

        return $this->successResponse(
            data: CouponUsageResource::collection($usages),
            message: 'Coupon usage history retrieved.',
        );
    }

    /**
     * Replace a coupon's product, category, and user scope.
     *
     * `sync` rather than individual attach/detach calls, so the request body
     * is always the complete, authoritative scope after saving — an admin
     * removing a product from the list actually removes it, rather than the
     * endpoint only ever being able to add.
     */
    private function syncScope(Coupon $coupon, StoreCouponRequest $request): void
    {
        if ($request->has('products')) {
            $coupon->products()->sync(collect($request->products())
                ->mapWithKeys(fn (array $row): array => [$row['id'] => ['is_excluded' => $row['excluded']]])
                ->all());
        }

        if ($request->has('categories')) {
            $coupon->categories()->sync(collect($request->categories())
                ->mapWithKeys(fn (array $row): array => [
                    $row['id'] => [
                        'is_excluded' => $row['excluded'],
                        'includes_descendants' => $row['includes_descendants'],
                    ],
                ])
                ->all());
        }

        if ($request->boolean('user_restricted')) {
            $coupon->users()->sync($request->userIds());
        } elseif ($request->has('user_restricted')) {
            // Restriction was explicitly turned off — the allow list is
            // meaningless without it, so it is cleared rather than left
            // stale for a future re-enable to silently reactivate.
            $coupon->users()->sync([]);
        }
    }
}
