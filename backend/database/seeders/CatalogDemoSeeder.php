<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\InventoryService;
use App\Services\VariantService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A small, realistic catalog for development and demos.
 *
 * Refuses to run in production. Unlike SettingsSeeder and
 * RolesAndPermissionsSeeder — which seed data the application genuinely needs —
 * this inserts sample products, and a live store's catalog is the operator's,
 * not something a deploy should write into.
 *
 * Idempotent: keyed on slug, so re-running updates rather than duplicates.
 */
final class CatalogDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('CatalogDemoSeeder skipped: sample products are not seeded in production.');

            return;
        }

        $categories = $this->seedCategories();
        $brands = $this->seedBrands();

        $this->seedSimpleProducts($categories, $brands);
        $this->seedVariableProduct($categories, $brands);

        $this->command?->info('Demo catalog seeded.');
    }

    /**
     * A three-level tree, so nesting is exercised rather than merely supported.
     *
     * @return array<string, Category>
     */
    private function seedCategories(): array
    {
        $clothing = $this->category('Clothing', null, 0);
        $shirts = $this->category('Shirts', $clothing, 0);
        $tShirts = $this->category('T-Shirts', $shirts, 0);
        $electronics = $this->category('Electronics', null, 1);
        $audio = $this->category('Audio', $electronics, 0);

        return [
            'clothing' => $clothing,
            'shirts' => $shirts,
            't-shirts' => $tShirts,
            'electronics' => $electronics,
            'audio' => $audio,
        ];
    }

    private function category(string $name, ?Category $parent, int $sortOrder): Category
    {
        return Category::query()->updateOrCreate(
            ['slug' => Str::slug($name)],
            [
                'name' => $name,
                'parent_id' => $parent?->getKey(),
                'description' => "Everything in {$name}.",
                'meta_title' => $name,
                'meta_description' => "Browse our range of {$name}.",
                'status' => ProductStatus::Published,
                'sort_order' => $sortOrder,
            ],
        );
    }

    /**
     * @return array<string, Brand>
     */
    private function seedBrands(): array
    {
        $brands = [];

        foreach (['Northwind', 'Contoso', 'Fabrikam'] as $index => $name) {
            $brands[Str::slug($name)] = Brand::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'description' => "{$name} products.",
                    'meta_title' => $name,
                    'status' => ProductStatus::Published,
                    'sort_order' => $index,
                ],
            );
        }

        return $brands;
    }

    /**
     * @param  array<string, Category>  $categories
     * @param  array<string, Brand>  $brands
     */
    private function seedSimpleProducts(array $categories, array $brands): void
    {
        $inventory = app(InventoryService::class);

        /** @var array<int, array{name: string, category: string, brand: string, price: int, discount: int|null, stock: int, featured: bool, type: ProductType}> $definitions */
        $definitions = [
            [
                'name' => 'Studio Wireless Headphones',
                'category' => 'audio',
                'brand' => 'contoso',
                'price' => 24_900,
                'discount' => 19_900,
                'stock' => 48,
                'featured' => true,
                'type' => ProductType::Simple,
            ],
            [
                'name' => 'Desk Microphone',
                'category' => 'audio',
                'brand' => 'fabrikam',
                'price' => 12_900,
                'discount' => null,
                // Below the default threshold of 5, so the low-stock report has
                // something to show on a fresh install.
                'stock' => 3,
                'featured' => false,
                'type' => ProductType::Simple,
            ],
            [
                'name' => 'Sound Design Sample Pack',
                'category' => 'audio',
                'brand' => 'contoso',
                'price' => 4_900,
                'discount' => null,
                'stock' => 0,
                'featured' => false,
                // Digital: unlimited stock, no weight, never out of stock.
                'type' => ProductType::Digital,
            ],
        ];

        foreach ($definitions as $definition) {
            $product = Product::query()->updateOrCreate(
                ['slug' => Str::slug($definition['name'])],
                [
                    'uuid' => (string) Str::uuid(),
                    'name' => $definition['name'],
                    'sku' => strtoupper(Str::slug($definition['name'], '')),
                    'short_description' => "A great {$definition['name']}.",
                    'description' => "Full description for {$definition['name']}.",
                    'category_id' => $categories[$definition['category']]->getKey(),
                    'brand_id' => $brands[$definition['brand']]->getKey(),
                    'type' => $definition['type'],
                    'price' => $definition['price'],
                    'discount_price' => $definition['discount'],
                    'cost_price' => (int) round($definition['price'] * 0.55),
                    'low_stock_threshold' => 5,
                    'weight' => $definition['type']->isShippable() ? 450 : null,
                    'status' => ProductStatus::Published,
                    'is_featured' => $definition['featured'],
                    'is_new_arrival' => true,
                    'meta_title' => $definition['name'],
                    'published_at' => now(),
                ],
            );

            /*
             * Stock is posted through the service rather than set on the model,
             * so the demo catalog has a real ledger behind it — seeding the
             * column directly would produce products whose history does not
             * explain their level.
             */
            $target = $definition['stock'];

            if ($definition['type']->tracksInventory() && (int) $product->stock !== $target) {
                $inventory->setLevel(
                    $product,
                    $target,
                    \App\Enums\StockMovementReason::InitialStock,
                    null,
                    'Seeded opening balance.',
                );
            }
        }
    }

    /**
     * A variable product with a generated size/colour matrix.
     *
     * @param  array<string, Category>  $categories
     * @param  array<string, Brand>  $brands
     */
    private function seedVariableProduct(array $categories, array $brands): void
    {
        $product = Product::query()->updateOrCreate(
            ['slug' => 'classic-cotton-t-shirt'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Classic Cotton T-Shirt',
                'sku' => 'CLASSICTEE',
                'short_description' => 'Soft combed cotton, cut for everyday wear.',
                'description' => 'A midweight cotton t-shirt available in several sizes and colours.',
                'category_id' => $categories['t-shirts']->getKey(),
                'brand_id' => $brands['northwind']->getKey(),
                'type' => ProductType::Variable,
                'price' => 2_900,
                'cost_price' => 1_200,
                'low_stock_threshold' => 5,
                'weight' => 180,
                'status' => ProductStatus::Published,
                'is_featured' => true,
                'is_best_seller' => true,
                'meta_title' => 'Classic Cotton T-Shirt',
                'published_at' => now(),
            ],
        );

        // Already generated on a previous run.
        if ($product->variants()->exists()) {
            return;
        }

        $size = Attribute::query()->where('slug', 'size')->with('values')->first();
        $colour = Attribute::query()->where('slug', 'colour')->with('values')->first();

        if ($size === null || $colour === null) {
            $this->command?->warn('Attributes not seeded; skipping variant generation.');

            return;
        }

        // 4 sizes x 3 colours = 12 variants, built in one pass.
        app(VariantService::class)->generateMatrix(
            $product,
            [
                $size->values->pluck('id')->all(),
                $colour->values->pluck('id')->all(),
            ],
            ['stock' => 25, 'low_stock_threshold' => 5],
        );
    }
}
