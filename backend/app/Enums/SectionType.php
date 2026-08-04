<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kinds of section a homepage can be assembled from.
 *
 * This enum is the contract between the admin panel and the storefront: the
 * panel offers exactly these types, the API labels every section with one, and
 * the frontend's renderer switches on the same strings. A section type the
 * frontend does not recognise is skipped rather than crashing the page, so the
 * two deployables can be released independently.
 *
 * Every type shares the same lifecycle — enable/disable, ordering, scheduling —
 * which is why they are rows in one table rather than one table per kind. What
 * differs is the *payload*, and that lives in the JSON `settings` column whose
 * shape each case declares below.
 */
enum SectionType: string
{
    case HeroSlider = 'hero_slider';
    case PromoBanner = 'promo_banner';
    case FeaturedProducts = 'featured_products';
    case NewArrivals = 'new_arrivals';
    case BestSellers = 'best_sellers';
    case Categories = 'categories';
    case FlashSale = 'flash_sale';
    case ProductCollection = 'product_collection';
    case Testimonials = 'testimonials';
    case BlogPosts = 'blog_posts';
    case CustomContent = 'custom_content';

    public function label(): string
    {
        return match ($this) {
            self::HeroSlider => 'Hero slider',
            self::PromoBanner => 'Promotional banner',
            self::FeaturedProducts => 'Featured products',
            self::NewArrivals => 'New arrivals',
            self::BestSellers => 'Best sellers',
            self::Categories => 'Categories',
            self::FlashSale => 'Flash sale',
            self::ProductCollection => 'Product collection',
            self::Testimonials => 'Testimonials',
            self::BlogPosts => 'Blog posts',
            self::CustomContent => 'Custom content',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::HeroSlider => 'A full-width rotating banner, usually the first thing on the page.',
            self::PromoBanner => 'One or more promotional images linking to a campaign.',
            self::FeaturedProducts => 'Products flagged as featured in the catalog.',
            self::NewArrivals => 'The most recently published products.',
            self::BestSellers => 'Products flagged as best sellers.',
            self::Categories => 'A grid of categories, either chosen explicitly or top-level.',
            self::FlashSale => 'A time-boxed offer with a countdown to its end.',
            self::ProductCollection => 'A hand-picked list of products in a chosen order.',
            self::Testimonials => 'Customer quotes.',
            self::BlogPosts => 'Recent posts from the blog.',
            self::CustomContent => 'Free-form rich text and an optional image.',
        };
    }

    /**
     * Whether this section's content is drawn from the catalog at render time.
     *
     * Catalog-backed sections hold only *selection rules* in their settings
     * (how many, which category); the products themselves are resolved live, so
     * a section never serves a product that has since been unpublished.
     */
    public function isCatalogBacked(): bool
    {
        return in_array($this, [
            self::FeaturedProducts,
            self::NewArrivals,
            self::BestSellers,
            self::FlashSale,
            self::ProductCollection,
            self::Categories,
        ], strict: true);
    }

    /**
     * Whether a section of this type may appear on the homepage more than once.
     *
     * Two "Featured products" rails would render identical content twice, which
     * is a configuration mistake rather than a design; two custom content
     * blocks or two banners are perfectly reasonable.
     */
    public function allowsMultiple(): bool
    {
        return in_array($this, [
            self::PromoBanner,
            self::ProductCollection,
            self::CustomContent,
            self::FlashSale,
        ], strict: true);
    }

    /**
     * Default settings applied when a section of this type is created.
     *
     * Returned to the admin panel as well, so the form can render the right
     * controls for a type without duplicating this knowledge in the frontend.
     *
     * @return array<string, mixed>
     */
    public function defaultSettings(): array
    {
        return match ($this) {
            self::HeroSlider => [
                'autoplay' => true,
                'interval' => 6000,
                'show_arrows' => true,
                'show_dots' => true,
                'height' => 'large',
            ],
            self::PromoBanner => [
                'layout' => 'full',
                'aspect_ratio' => '21:9',
            ],
            self::FeaturedProducts, self::NewArrivals, self::BestSellers => [
                'limit' => 8,
                'columns' => 4,
                'show_view_all' => true,
            ],
            self::Categories => [
                'limit' => 8,
                'columns' => 4,
                'category_ids' => [],
                'show_product_count' => true,
            ],
            self::FlashSale => [
                'limit' => 8,
                'columns' => 4,
                'product_ids' => [],
                'show_countdown' => true,
            ],
            self::ProductCollection => [
                'limit' => 8,
                'columns' => 4,
                'product_ids' => [],
                'category_id' => null,
            ],
            self::Testimonials => [
                'columns' => 3,
                'items' => [],
            ],
            self::BlogPosts => [
                'limit' => 3,
                'columns' => 3,
            ],
            self::CustomContent => [
                'content' => '',
                'image' => null,
                'image_position' => 'right',
                'cta_label' => null,
                'cta_url' => null,
            ],
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The catalogue the admin panel renders as its "add section" menu.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function catalogue(): array
    {
        return array_map(static fn (self $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
            'description' => $type->description(),
            'allows_multiple' => $type->allowsMultiple(),
            'default_settings' => $type->defaultSettings(),
        ], self::cases());
    }
}
