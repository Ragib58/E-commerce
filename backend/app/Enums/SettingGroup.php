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
    case Analytics = 'analytics';
    case Business = 'business';
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
            self::Seo => 'SEO & Website',
            self::Analytics => 'Analytics & Tracking',
            self::Business => 'Business Rules',
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
            self::Seo => 'Website title, meta tags, and indexing controls.',
            self::Analytics => 'Third-party measurement tag identifiers.',
            self::Business => 'Currency, tax rates, and document numbering.',
            self::Mail => 'Outbound mail sender identity.',
            self::Payment => 'Payment gateway configuration.',
            self::Shipping => 'Shipping rules and defaults.',
            self::Feature => 'Toggles for optional storefront features.',
        };
    }

    /**
     * Icon rendered beside the group in the admin panel's tab strip.
     */
    public function icon(): string
    {
        return match ($this) {
            self::General => 'building-storefront',
            self::Branding => 'photo',
            self::Theme => 'swatch',
            self::Contact => 'phone',
            self::Social => 'share',
            self::Seo => 'magnifying-glass',
            self::Analytics => 'chart-bar',
            self::Business => 'banknotes',
            self::Mail => 'envelope',
            self::Payment => 'credit-card',
            self::Shipping => 'truck',
            self::Feature => 'toggle',
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
            // Measurement IDs are public by nature — a GA or Pixel tag is
            // readable in the page source of any site that uses one. They are
            // exposed so the storefront can inject the tags without a rebuild.
            self::Analytics,
            // Currency symbol and tax rate are needed to render prices; the
            // order/invoice prefixes are not secret either.
            self::Business,
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
