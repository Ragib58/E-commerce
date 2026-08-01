<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Logical grouping for dynamic settings.
 *
 * Drives the admin panel's tab layout and lets the public API return a single
 * group (`/settings/public?group=branding`) instead of the whole set.
 */
enum SettingGroup: string
{
    case General = 'general';
    case Branding = 'branding';
    case Theme = 'theme';
    case Contact = 'contact';
    case Social = 'social';
    case Seo = 'seo';
    case Mail = 'mail';
    case Payment = 'payment';
    case Shipping = 'shipping';
    case Feature = 'feature';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General',
            self::Branding => 'Branding',
            self::Theme => 'Theme & Colours',
            self::Contact => 'Contact Information',
            self::Social => 'Social Media',
            self::Seo => 'SEO',
            self::Mail => 'Mail',
            self::Payment => 'Payment',
            self::Shipping => 'Shipping',
            self::Feature => 'Feature Flags',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::General => 'Company name, tagline, and site-wide defaults.',
            self::Branding => 'Logo, favicon, and visual identity assets.',
            self::Theme => 'Brand and theme colours applied to the storefront.',
            self::Contact => 'Address, phone numbers, and support email.',
            self::Social => 'Links to social media profiles.',
            self::Seo => 'Default meta tags and indexing controls.',
            self::Mail => 'Outbound mail sender identity.',
            self::Payment => 'Payment gateway configuration.',
            self::Shipping => 'Shipping rules and defaults.',
            self::Feature => 'Toggles for optional storefront features.',
        };
    }

    /**
     * Groups that may be exposed through the public (unauthenticated) API.
     *
     * This is a defence-in-depth companion to the per-row `is_public` flag:
     * a setting must satisfy both to reach the storefront.
     *
     * @return array<int, self>
     */
    public static function publiclyExposable(): array
    {
        return [
            self::General,
            self::Branding,
            self::Theme,
            self::Contact,
            self::Social,
            self::Seo,
            self::Feature,
        ];
    }

    public function isPubliclyExposable(): bool
    {
        return in_array($this, self::publiclyExposable(), strict: true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
