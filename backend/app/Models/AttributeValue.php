<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * One permitted value of an attribute — "Large", "Red".
 *
 * @property int $id
 * @property int $attribute_id
 * @property string $value
 * @property string $slug
 * @property string|null $colour_code
 * @property int $sort_order
 */
class AttributeValue extends Model
{
    /** @use HasFactory<\Database\Factories\AttributeValueFactory> */
    use HasFactory;

    protected $fillable = [
        'attribute_id',
        'value',
        'slug',
        'colour_code',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Attribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    /**
     * @return BelongsToMany<ProductVariant, $this>
     */
    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductVariant::class,
            'attribute_value_variant',
            'attribute_value_id',
            'product_variant_id',
        );
    }

    /**
     * Slugs are unique per attribute, not globally — "Size: small" and
     * "Colour: small" are unrelated and may coexist.
     */
    public static function generateSlug(string $value, int $attributeId, ?int $ignoreId = null): string
    {
        $base = Str::slug($value) ?: 'value';
        $slug = $base;
        $suffix = 2;

        while (
            static::query()
                ->where('attribute_id', $attributeId)
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
