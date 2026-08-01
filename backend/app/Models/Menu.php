<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MenuLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named, admin-managed navigation menu bound to a storefront location.
 *
 * The frontend requests menus by location rather than by id, so an admin can
 * point the header at a different menu without a frontend change.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property MenuLocation $location
 * @property bool $is_active
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MenuItem> $items
 */
class Menu extends Model
{
    /** @use HasFactory<\Database\Factories\MenuFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'location',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'location' => MenuLocation::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Top-level items only. Children are eager-loaded recursively via
     * `items.children`, which keeps the tree query bounded and predictable
     * instead of issuing one query per level.
     *
     * @return HasMany<MenuItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)
            ->whereNull('parent_id')
            ->orderBy('sort_order');
    }

    /**
     * Every item in the menu regardless of depth.
     *
     * @return HasMany<MenuItem, $this>
     */
    public function allItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    /**
     * @param  Builder<Menu>  $query
     * @return Builder<Menu>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Menu>  $query
     * @return Builder<Menu>
     */
    public function scopeLocation(Builder $query, MenuLocation|string $location): Builder
    {
        return $query->where('location', $location instanceof MenuLocation ? $location->value : $location);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
