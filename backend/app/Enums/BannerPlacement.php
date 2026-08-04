<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where on the storefront a banner is eligible to appear.
 *
 * Placement is a property of the banner rather than of the page that renders
 * it, so an operator can retire a campaign by changing one row instead of
 * hunting for every section that references it.
 *
 * Homepage sections of type `hero_slider` and `promo_banner` pull their slides
 * from banners filtered by placement, which is what keeps slide management in
 * one screen rather than duplicated inside every section's settings.
 */
enum BannerPlacement: string
{
    case HeroSlider = 'hero_slider';
    case HomepagePromo = 'homepage_promo';
    case CategoryTop = 'category_top';
    case Sidebar = 'sidebar';
    case Checkout = 'checkout';
    case Popup = 'popup';

    public function label(): string
    {
        return match ($this) {
            self::HeroSlider => 'Hero slider',
            self::HomepagePromo => 'Homepage promotion',
            self::CategoryTop => 'Category page header',
            self::Sidebar => 'Sidebar',
            self::Checkout => 'Checkout',
            self::Popup => 'Popup',
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
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $placement): array => [
            'value' => $placement->value,
            'label' => $placement->label(),
        ], self::cases());
    }
}
