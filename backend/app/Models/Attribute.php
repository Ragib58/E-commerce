<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A dimension a product can vary by — Size, Colour, Material.
 *
 * Rows, not an enum: an operator adds "Material" from the admin panel without a
 * migration. `display_type` lets each attribute pick its own storefront control,
 * so the frontend renders a swatch row for colour and buttons for size from the
 * same component without knowing either name.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $display_type
 * @property bool $is_filterable
 * @property int $sort_order
 */
class Attribute extends Model
{
    /** @use HasFactory<\Database\Factories\AttributeFactory> */
    use HasFactory;

    /**
     * Storefront controls an attribute may be rendered with.
     */
    public const DISPLAY_TYPES = ['button', 'swatch', 'dropdown', 'radio'];

    protected $fillable = [
        'name',
        'slug',
        'display_type',
        'is_filterable',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_filterable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<AttributeValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class)->orderBy('sort_order');
    }

    /**
     * @param  Builder<Attribute>  $query
     * @return Builder<Attribute>
     */
    public function scopeFilterable(Builder $query): Builder
    {
        return $query->where('is_filterable', true);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public static function generateSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'attribute';
        $slug = $base;
        $suffix = 2;

        while (
            static::query()
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
