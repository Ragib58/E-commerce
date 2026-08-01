<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Named slots on the storefront that a dynamic menu can be attached to.
 *
 * The frontend requests a menu by location (`/menus/header`), never by id, so
 * an admin can swap which menu fills a slot without a frontend change.
 */
enum MenuLocation: string
{
    case Header = 'header';
    case FooterPrimary = 'footer_primary';
    case FooterSecondary = 'footer_secondary';
    case Sidebar = 'sidebar';
    case Mobile = 'mobile';
    case TopBar = 'top_bar';

    public function label(): string
    {
        return match ($this) {
            self::Header => 'Header Navigation',
            self::FooterPrimary => 'Footer — Primary Column',
            self::FooterSecondary => 'Footer — Secondary Column',
            self::Sidebar => 'Sidebar',
            self::Mobile => 'Mobile Navigation',
            self::TopBar => 'Top Announcement Bar',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
