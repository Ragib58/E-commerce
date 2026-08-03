<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductMedia;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductMedia>
 */
final class ProductMediaFactory extends Factory
{
    protected $model = ProductMedia::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'product_variant_id' => null,
            'type' => ProductMedia::TYPE_IMAGE,

            // A disk-relative path, matching what MediaService stores. An
            // absolute URL here would bypass the URL expansion under test.
            'path' => 'products/' . Str::lower(Str::random(12)) . '.jpg',

            'alt_text' => $this->faker->sentence(4),
            'is_thumbnail' => false,
            'sort_order' => 0,
        ];
    }

    public function thumbnail(): self
    {
        return $this->state(fn (): array => ['is_thumbnail' => true]);
    }

    public function video(string $url = 'https://example.com/video.mp4'): self
    {
        return $this->state(fn (): array => [
            'type' => ProductMedia::TYPE_VIDEO,
            'path' => $url,
        ]);
    }

    public function forProduct(Product $product): self
    {
        return $this->state(fn (): array => ['product_id' => $product->getKey()]);
    }
}
