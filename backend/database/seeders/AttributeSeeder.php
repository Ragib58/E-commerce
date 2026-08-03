<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Attribute;
use App\Models\AttributeValue;
use Illuminate\Database\Seeder;

/**
 * Seeds the variant attributes a typical store starts with.
 *
 * These are ordinary rows, not fixtures the code depends on: an operator can
 * rename "Colour", delete it, or add "Material" from the admin panel. They
 * exist only so a fresh install can build a variable product immediately
 * instead of defining the vocabulary first.
 *
 * Idempotent — `updateOrCreate` on the slug, so re-running never duplicates a
 * value or overwrites an operator's edits to sort order.
 */
final class AttributeSeeder extends Seeder
{
    /**
     * @var array<string, array{display_type: string, sort_order: int, values: array<int, array{value: string, colour_code?: string}>}>
     */
    private const ATTRIBUTES = [
        'size' => [
            'display_type' => 'button',
            'sort_order' => 0,
            'values' => [
                ['value' => 'S'],
                ['value' => 'M'],
                ['value' => 'L'],
                ['value' => 'XL'],
            ],
        ],
        'colour' => [
            'display_type' => 'swatch',
            'sort_order' => 1,
            'values' => [
                ['value' => 'Red', 'colour_code' => '#dc2626'],
                ['value' => 'Blue', 'colour_code' => '#2563eb'],
                ['value' => 'Black', 'colour_code' => '#111827'],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::ATTRIBUTES as $slug => $definition) {
            $attribute = Attribute::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => ucfirst($slug),
                    'display_type' => $definition['display_type'],
                    'is_filterable' => true,
                    'sort_order' => $definition['sort_order'],
                ],
            );

            foreach ($definition['values'] as $index => $value) {
                AttributeValue::query()->updateOrCreate(
                    [
                        'attribute_id' => $attribute->getKey(),
                        'slug' => \Illuminate\Support\Str::slug($value['value']),
                    ],
                    [
                        'value' => $value['value'],
                        'colour_code' => $value['colour_code'] ?? null,
                        'sort_order' => $index,
                    ],
                );
            }
        }
    }
}
