<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ShippingZoneFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A named geographic area — "Inside Dhaka", "Outside Dhaka" — that shipping
 * rates can price differently.
 *
 * Matching is against explicit lists of countries, states, cities, and
 * postcodes rather than geometry; see the migration for why. {@see matches()}
 * is the single place an address is compared against a zone, so the storefront
 * quote, the checkout price, and the admin's "which zone is this" preview all
 * agree by construction.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property array<int, string>|null $countries
 * @property array<int, string>|null $states
 * @property array<int, string>|null $cities
 * @property array<int, string>|null $postcodes
 * @property int $priority
 * @property bool $is_fallback
 * @property bool $is_active
 */
class ShippingZone extends Model
{
    /** @use HasFactory<ShippingZoneFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'name',
        'code',
        'description',
        'countries',
        'states',
        'cities',
        'postcodes',
        'priority',
        'is_fallback',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'countries' => 'array',
            'states' => 'array',
            'cities' => 'array',
            'postcodes' => 'array',
            'priority' => 'integer',
            'is_fallback' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $zone): void {
            $zone->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return HasMany<ShippingRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(ShippingRate::class);
    }

    /**
     * Orders whose delivery address resolved to this zone at placement.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Whether a delivery address falls inside this zone.
     *
     * A zone with none of the four lists set matches nothing — an empty zone is
     * a configuration mistake, not a universal one, and treating it as "matches
     * everywhere" would let a half-created zone silently steal every order from
     * the zone actually meant to serve them. `is_fallback` is the explicit way
     * to say "match anything else".
     *
     * Every comparison is case-insensitive and trimmed: an operator typing
     * "Dhaka" and a customer typing "dhaka " must resolve to the same zone.
     *
     * Postcodes support one trailing wildcard, `120*`, matched as a prefix —
     * postal systems are hierarchical, and requiring every code to be listed by
     * hand is how a zone ends up quietly incomplete.
     */
    public function matches(?string $country, ?string $state, ?string $city, ?string $postcode): bool
    {
        if ($this->is_fallback) {
            return true;
        }

        $hasAnyCriteria = false;
        $matchedAny = false;

        foreach ([
            ['countries', $country],
            ['states', $state],
            ['cities', $city],
            ['postcodes', $postcode],
        ] as [$field, $value]) {
            $list = $this->{$field};

            if ($list === null || $list === []) {
                continue;
            }

            $hasAnyCriteria = true;

            if ($value !== null && $this->listContains($list, $value, $field === 'postcodes')) {
                $matchedAny = true;
            }
        }

        // A zone with no criteria at all never matches an ordinary address —
        // see the method docblock. Only a fallback zone (handled above) may.
        return $hasAnyCriteria && $matchedAny;
    }

    /**
     * @param  array<int, string>  $list
     */
    private function listContains(array $list, string $value, bool $allowWildcard): bool
    {
        $needle = mb_strtolower(trim($value));

        foreach ($list as $candidate) {
            $candidate = mb_strtolower(trim((string) $candidate));

            if ($allowWildcard && str_ends_with($candidate, '*')) {
                if (str_starts_with($needle, rtrim($candidate, '*'))) {
                    return true;
                }

                continue;
            }

            if ($candidate === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Builder<ShippingZone>  $query
     * @return Builder<ShippingZone>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Active zones, most specific first — the order resolution must check them in.
     *
     * @param  Builder<ShippingZone>  $query
     * @return Builder<ShippingZone>
     */
    public function scopeForResolution(Builder $query): Builder
    {
        return $query->active()->orderByDesc('priority');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
