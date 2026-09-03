<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ShippingMethod;
use App\Models\ShippingRate;
use App\Models\ShippingZone;

/**
 * Resolves a delivery address to a zone and prices a method within it.
 *
 * ## The one place a zoned shipping charge is decided
 *
 * `ShippingMethod::rateFor()` remains the base case — a method with no zone
 * overrides prices exactly as it did before this phase existed. This class sits
 * above it: {@see quote()} is what checkout, order placement, and the admin
 * quote preview all call, so "which zone did this address resolve to" and "what
 * does the method cost there" can never be answered two different ways by two
 * different code paths.
 *
 * ## Resolution order
 *
 * 1. Find the highest-priority active zone whose criteria match the address.
 * 2. If the method has an active rate row for that zone whose subtotal band
 *    covers the order, use it.
 * 3. Otherwise fall back to the method's own `rate` / `free_above` columns.
 *
 * A method is never *unavailable* purely because no rate row exists for a
 * zone — the fallback is what keeps an operator from having to price every
 * method for every zone before any of them can be offered.
 */
final class ShippingZoneService
{
    /**
     * The best-matching zone for a delivery address, or null when nothing
     * matches and no fallback zone is configured.
     *
     * Read once per quote and reused, rather than resolved separately for each
     * candidate method — the address does not change between methods, and
     * re-querying per method would turn an N-method checkout page into N
     * identical zone lookups.
     */
    public function resolveZone(
        ?string $country,
        ?string $state = null,
        ?string $city = null,
        ?string $postcode = null,
    ): ?ShippingZone {
        // Ordered by priority so the most specific match — "Inside Dhaka"
        // over "Bangladesh" — is checked first and wins as soon as it matches.
        foreach (ShippingZone::query()->forResolution()->get() as $zone) {
            if ($zone->matches($country, $state, $city, $postcode)) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * What a method costs for an order, in a resolved zone.
     *
     * @return array{amount: int, source: string, min_days: ?int, max_days: ?int}
     */
    public function quote(ShippingMethod $method, int $subtotal, ?ShippingZone $zone): array
    {
        $rate = $zone !== null ? $this->rateFor($method, $zone, $subtotal) : null;

        if ($rate !== null) {
            return [
                'amount' => $rate->amountFor($subtotal),
                'source' => 'zone',
                'min_days' => $rate->min_days ?? $method->min_days,
                'max_days' => $rate->max_days ?? $method->max_days,
            ];
        }

        return [
            'amount' => $method->rateFor($subtotal),
            'source' => 'method',
            'min_days' => $method->min_days,
            'max_days' => $method->max_days,
        ];
    }

    /**
     * The active rate row for this method and zone that covers the subtotal,
     * cheapest first when several bands could apply.
     */
    private function rateFor(ShippingMethod $method, ShippingZone $zone, int $subtotal): ?ShippingRate
    {
        return ShippingRate::query()
            ->where('shipping_method_id', $method->getKey())
            ->where('shipping_zone_id', $zone->getKey())
            ->active()
            ->coveringSubtotal($subtotal)
            ->orderBy('rate')
            ->first();
    }

    /**
     * Whether a method is available in the resolved zone at all.
     *
     * A method restricted to specific zones (via its rate rows being the only
     * source of a price) is still offered under the method-level fallback
     * unless the operator has explicitly deactivated it — zones narrow price,
     * not availability, unless a rate row exists and is inactive for exactly
     * this zone and subtotal, which is read as "not offered here".
     */
    public function isAvailableInZone(ShippingMethod $method, int $subtotal, ?ShippingZone $zone): bool
    {
        if ($zone === null) {
            return true;
        }

        $hasInactiveMatch = ShippingRate::query()
            ->where('shipping_method_id', $method->getKey())
            ->where('shipping_zone_id', $zone->getKey())
            ->where('is_active', false)
            ->coveringSubtotal($subtotal)
            ->exists();

        return ! $hasInactiveMatch;
    }
}
