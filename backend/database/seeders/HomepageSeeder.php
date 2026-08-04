<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SectionType;
use App\Models\HomepageSection;
use Illuminate\Database\Seeder;

/**
 * Seeds a default homepage layout.
 *
 * A fresh install with zero sections renders an empty storefront, which looks
 * broken rather than unconfigured — so the common arrangement is seeded and the
 * operator rearranges it. Every row is ordinary data: it can be reordered,
 * disabled, scheduled, or deleted from the panel, and nothing in the code
 * depends on any of these sections existing.
 *
 * Idempotent by section type. Re-running never duplicates a rail, and never
 * resurrects a section the operator deliberately deleted — deletion is a soft
 * delete, and the existence check includes trashed rows for exactly that
 * reason.
 */
final class HomepageSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->sections() as $index => $section) {
            $exists = HomepageSection::withTrashed()
                ->where('type', $section['type']->value)
                ->exists();

            if ($exists) {
                continue;
            }

            HomepageSection::query()->create([
                'type' => $section['type'],
                'name' => $section['name'],
                'heading' => $section['heading'],
                'subheading' => $section['subheading'] ?? null,
                // Merged over the type's defaults, so a section is complete
                // even where the seeder overrides only one key.
                'settings' => array_merge(
                    $section['type']->defaultSettings(),
                    $section['settings'] ?? [],
                ),
                'is_enabled' => $section['is_enabled'] ?? true,
                'sort_order' => $index * 10,
            ]);
        }
    }

    /**
     * The default arrangement, top to bottom.
     *
     * Sort orders are spaced by ten so an operator can drop a section between
     * two others without the reorder endpoint having to renumber the whole page
     * — though it handles that too.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sections(): array
    {
        return [
            [
                'type' => SectionType::HeroSlider,
                'name' => 'Main hero',
                'heading' => null,
                'settings' => ['height' => 'large', 'interval' => 6000],

                /*
                 * Enabled, but it renders nothing until banners are uploaded:
                 * HomepageService drops a section whose content resolves empty,
                 * so a fresh store shows no blank hero placeholder.
                 */
            ],
            [
                'type' => SectionType::Categories,
                'name' => 'Shop by category',
                'heading' => 'Shop by category',
                'subheading' => 'Browse the range',
                'settings' => ['limit' => 8, 'columns' => 4],
            ],
            [
                'type' => SectionType::FeaturedProducts,
                'name' => 'Featured products',
                'heading' => 'Featured',
                'subheading' => 'Hand-picked from the catalog',
                'settings' => ['limit' => 8, 'columns' => 4],
            ],
            [
                'type' => SectionType::PromoBanner,
                'name' => 'Mid-page promotion',
                'heading' => null,
                'settings' => ['layout' => 'full', 'aspect_ratio' => '21:9'],
            ],
            [
                'type' => SectionType::NewArrivals,
                'name' => 'New arrivals',
                'heading' => 'New arrivals',
                'subheading' => 'The latest additions',
                'settings' => ['limit' => 8, 'columns' => 4],
            ],
            [
                'type' => SectionType::BestSellers,
                'name' => 'Best sellers',
                'heading' => 'Best sellers',
                'subheading' => 'What everyone is buying',
                'settings' => ['limit' => 8, 'columns' => 4],
            ],
            [
                'type' => SectionType::Testimonials,
                'name' => 'Customer testimonials',
                'heading' => 'What our customers say',
                'settings' => ['columns' => 3, 'items' => []],

                // Disabled: there are no testimonials to show on a new store,
                // and inventing some would put words in customers' mouths.
                'is_enabled' => false,
            ],
        ];
    }
}
