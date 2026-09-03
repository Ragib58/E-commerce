<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreShippingMethodRequest;
use App\Http\Requests\Api\V1\Admin\StoreShippingRateRequest;
use App\Http\Requests\Api\V1\Admin\StoreShippingZoneRequest;
use App\Http\Requests\Api\V1\Admin\UpdateShippingMethodRequest;
use App\Http\Requests\Api\V1\Admin\UpdateShippingZoneRequest;
use App\Http\Resources\Api\V1\ShippingMethodResource;
use App\Http\Resources\Api\V1\ShippingRateResource;
use App\Http\Resources\Api\V1\ShippingZoneResource;
use App\Models\ShippingMethod;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Services\ShippingZoneService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Shipping administration: methods, zones, and the rates that join them.
 *
 * Everything here is data an operator edits at runtime — a method, a zone, and
 * the price of one within the other are all rows, never a code change. That is
 * the same "fully dynamic" rule the rest of the admin surface follows: adding
 * "Same-day, Dhaka only" is an INSERT, and pricing "Express" differently
 * outside Dhaka is another.
 *
 * Read paths accept `view_shipping` as well as `manage_shipping`, so a
 * read-only role — support staff quoting a delivery estimate on the phone —
 * can see configuration without being able to change what the storefront
 * charges.
 */
final class ShippingController extends Controller
{
    use ApiResponse;

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * GET /admin/shipping/methods
     */
    public function methods(): JsonResponse
    {
        $methods = ShippingMethod::query()
            ->withCount('rates')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->successResponse(
            data: ShippingMethodResource::collection($methods),
            message: 'Shipping methods retrieved.',
        );
    }

    public function showMethod(ShippingMethod $method): JsonResponse
    {
        $method->load(['rates.zone']);

        return $this->successResponse(
            data: new ShippingMethodResource($method),
            message: 'Shipping method retrieved.',
        );
    }

    public function storeMethod(StoreShippingMethodRequest $request): JsonResponse
    {
        $method = ShippingMethod::query()->create(array_merge(
            $request->payload(),
            ['uuid' => (string) Str::uuid()],
        ));

        return $this->createdResponse(
            data: new ShippingMethodResource($method),
            message: 'Shipping method created.',
        );
    }

    public function updateMethod(UpdateShippingMethodRequest $request, ShippingMethod $method): JsonResponse
    {
        $method->update($request->payload());

        return $this->successResponse(
            data: new ShippingMethodResource($method->refresh()),
            message: 'Shipping method updated.',
        );
    }

    /**
     * DELETE /admin/shipping/methods/{method}
     *
     * Refused when orders reference it. A method with order history behind it
     * is a fact about the past — deleting the row would either orphan those
     * orders' `shipping_method_id` or, worse, silently rewrite what they were
     * shipped by. An operator who wants it gone from checkout deactivates it
     * instead; that leaves history intact and simply stops offering it.
     */
    public function destroyMethod(ShippingMethod $method): JsonResponse
    {
        if ($method->orders()->exists()) {
            return $this->errorResponse(
                message: 'This method has orders against it and cannot be deleted. Deactivate it instead.',
                status: 409,
                code: 'SHIPPING_METHOD_IN_USE',
            );
        }

        $method->delete();

        return $this->successResponse(message: 'Shipping method deleted.');
    }

    /*
    |--------------------------------------------------------------------------
    | Zones
    |--------------------------------------------------------------------------
    */

    /**
     * GET /admin/shipping/zones
     */
    public function zones(): JsonResponse
    {
        $zones = ShippingZone::query()
            ->withCount('rates')
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();

        return $this->successResponse(
            data: ShippingZoneResource::collection($zones),
            message: 'Shipping zones retrieved.',
        );
    }

    public function showZone(ShippingZone $zone): JsonResponse
    {
        $zone->load(['rates.shippingMethod']);

        return $this->successResponse(
            data: new ShippingZoneResource($zone),
            message: 'Shipping zone retrieved.',
        );
    }

    public function storeZone(StoreShippingZoneRequest $request): JsonResponse
    {
        $zone = ShippingZone::query()->create(array_merge(
            $request->payload(),
            ['uuid' => (string) Str::uuid()],
        ));

        return $this->createdResponse(
            data: new ShippingZoneResource($zone),
            message: 'Shipping zone created.',
        );
    }

    public function updateZone(UpdateShippingZoneRequest $request, ShippingZone $zone): JsonResponse
    {
        $zone->update($request->payload());

        return $this->successResponse(
            data: new ShippingZoneResource($zone->refresh()),
            message: 'Shipping zone updated.',
        );
    }

    /**
     * DELETE /admin/shipping/zones/{zone}
     *
     * Refused when orders reference it, for the same reason as a method.
     * `shipping_zone_id` is `nullOnDelete` at the database level — deleting
     * anyway would silently blank out which zone historical orders were
     * shipped to, which is exactly the kind of quiet data loss a foreign key
     * constraint exists to prevent an operator from triggering by accident.
     */
    public function destroyZone(ShippingZone $zone): JsonResponse
    {
        if ($zone->orders()->exists()) {
            return $this->errorResponse(
                message: 'This zone has orders against it and cannot be deleted. Deactivate it instead.',
                status: 409,
                code: 'SHIPPING_ZONE_IN_USE',
            );
        }

        $zone->delete();

        return $this->successResponse(message: 'Shipping zone deleted.');
    }

    /*
    |--------------------------------------------------------------------------
    | Rates — the join between a method and a zone
    |--------------------------------------------------------------------------
    */

    /**
     * POST /admin/shipping/methods/{method}/rates
     *
     * Creates or replaces the rate for this method in the named zone and
     * subtotal band. "Replaces" rather than erroring on a duplicate: an
     * operator adjusting a price for a zone they already priced is the
     * ordinary case, not a mistake to be blocked.
     */
    public function storeRate(StoreShippingRateRequest $request, ShippingMethod $method): JsonResponse
    {
        $validated = $request->validated();

        $rate = ShippingRate::query()->updateOrCreate(
            [
                'shipping_method_id' => $method->getKey(),
                'shipping_zone_id' => $validated['shipping_zone_id'],
                'min_subtotal_key' => $validated['min_subtotal'] ?? 0,
            ],
            $validated,
        );

        $rate->load(['shippingMethod', 'zone']);

        return $this->createdResponse(
            data: new ShippingRateResource($rate),
            message: 'Shipping rate saved.',
        );
    }

    /**
     * DELETE /admin/shipping/rates/{rate}
     *
     * Removing a rate is safe unconditionally — unlike a method or a zone, no
     * order references a rate row directly. An order's `shipping_total` is a
     * snapshot captured at placement, so a rate deleted afterwards cannot
     * rewrite what that order actually cost to ship.
     */
    public function destroyRate(ShippingRate $rate): JsonResponse
    {
        $rate->delete();

        return $this->successResponse(message: 'Shipping rate removed.');
    }

    /*
    |--------------------------------------------------------------------------
    | Quote preview
    |--------------------------------------------------------------------------
    */

    /**
     * GET /admin/shipping/quote
     *
     * Lets an operator check "what would this actually cost" for a given
     * address and order size without placing a test order. Uses the exact
     * same ShippingZoneService the storefront and OrderService call, so the
     * preview can never disagree with what a real checkout would charge.
     */
    public function quote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country' => ['nullable', 'string', 'size:2'],
            'state' => ['nullable', 'string', 'max:128'],
            'city' => ['nullable', 'string', 'max:128'],
            'postcode' => ['nullable', 'string', 'max:32'],
            'subtotal' => ['required', 'integer', 'min:0'],
        ]);

        $zoneService = app(ShippingZoneService::class);

        $zone = $zoneService->resolveZone(
            country: $validated['country'] ?? null,
            state: $validated['state'] ?? null,
            city: $validated['city'] ?? null,
            postcode: $validated['postcode'] ?? null,
        );

        $subtotal = (int) $validated['subtotal'];

        $methods = ShippingMethod::query()
            ->active()
            ->ordered()
            ->get()
            ->filter(fn (ShippingMethod $method): bool => $method->isAvailableFor($subtotal, $validated['country'] ?? null)
                && $zoneService->isAvailableInZone($method, $subtotal, $zone))
            ->map(function (ShippingMethod $method) use ($zoneService, $subtotal, $zone): array {
                $quote = $zoneService->quote($method, $subtotal, $zone);

                return [
                    'method' => $method->name,
                    'rate' => $quote['amount'],
                    'source' => $quote['source'],
                ];
            })
            ->values();

        return $this->successResponse(
            data: [
                'zone' => $zone === null ? null : [
                    'id' => $zone->uuid,
                    'name' => $zone->name,
                ],
                'methods' => $methods,
            ],
            message: 'Shipping quote calculated.',
        );
    }
}
