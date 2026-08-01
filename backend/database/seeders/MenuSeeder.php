<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MenuLocation;
use App\Models\Menu;
use App\Models\MenuItem;
use Illuminate\Database\Seeder;

/**
 * Seeds default header and footer navigation.
 *
 * Idempotent on the menu slug. Items are only created when the menu is new, so
 * re-seeding never duplicates entries an administrator has since reorganised.
 */
final class MenuSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->definitions() as $definition) {
            $menu = Menu::query()->firstOrNew(['slug' => $definition['slug']]);

            $isNew = ! $menu->exists;

            $menu->fill([
                'name' => $definition['name'],
                'location' => $definition['location'],
                'is_active' => true,
            ])->save();

            if ($isNew) {
                $this->createItems($menu, $definition['items']);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function createItems(Menu $menu, array $items, ?int $parentId = null): void
    {
        foreach ($items as $index => $item) {
            $created = MenuItem::query()->create([
                'menu_id' => $menu->id,
                'parent_id' => $parentId,
                'label' => $item['label'],
                'url' => $item['url'] ?? null,
                'icon' => $item['icon'] ?? null,
                'target' => $item['target'] ?? '_self',
                'is_active' => true,
                'sort_order' => $index + 1,
            ]);

            if (isset($item['children'])) {
                $this->createItems($menu, $item['children'], $created->id);
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function definitions(): array
    {
        return [
            [
                'name' => 'Main Navigation',
                'slug' => 'main-navigation',
                'location' => MenuLocation::Header,
                'items' => [
                    ['label' => 'Home', 'url' => '/'],
                    ['label' => 'Shop', 'url' => '/products'],
                    ['label' => 'Categories', 'url' => '/categories'],
                    ['label' => 'Brands', 'url' => '/brands'],
                    ['label' => 'Contact', 'url' => '/contact'],
                ],
            ],
            [
                'name' => 'Footer — Company',
                'slug' => 'footer-company',
                'location' => MenuLocation::FooterPrimary,
                'items' => [
                    ['label' => 'About Us', 'url' => '/about'],
                    ['label' => 'Contact', 'url' => '/contact'],
                    ['label' => 'Careers', 'url' => '/careers'],
                    ['label' => 'Blog', 'url' => '/blog'],
                ],
            ],
            [
                'name' => 'Footer — Support',
                'slug' => 'footer-support',
                'location' => MenuLocation::FooterSecondary,
                'items' => [
                    ['label' => 'Shipping Information', 'url' => '/shipping'],
                    ['label' => 'Returns & Refunds', 'url' => '/returns'],
                    ['label' => 'Privacy Policy', 'url' => '/privacy'],
                    ['label' => 'Terms of Service', 'url' => '/terms'],
                ],
            ],
        ];
    }
}
