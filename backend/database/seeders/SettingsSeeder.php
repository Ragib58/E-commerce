<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SettingGroup;
use App\Enums\SettingType;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Seeds the default storefront configuration.
 *
 * These are *defaults*, not constants — every one of them is editable from the
 * admin panel at runtime. They exist so a fresh install renders a coherent
 * site before an administrator has configured anything.
 *
 * Idempotent: uses updateOrCreate keyed on `key` and never overwrites a value
 * an administrator has already changed, so re-running `db:seed` on a live
 * environment is safe.
 */
final class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->definitions() as $definition) {
            /** @var Setting|null $existing */
            $existing = Setting::query()->where('key', $definition['key'])->first();

            if ($existing !== null) {
                // Refresh presentation metadata (label, group, type) but leave
                // the administrator's value untouched.
                $existing->fill([
                    'type' => $definition['type'],
                    'group' => $definition['group'],
                    'label' => $definition['label'],
                    'description' => $definition['description'] ?? null,
                    'is_public' => $definition['is_public'],
                    'is_locked' => $definition['is_locked'] ?? false,
                    'sort_order' => $definition['sort_order'],
                ])->save();

                continue;
            }

            /** @var SettingType $type */
            $type = $definition['type'];

            Setting::query()->create([
                'key' => $definition['key'],
                'value' => $type->serialize($definition['value']),
                'type' => $type,
                'group' => $definition['group'],
                'label' => $definition['label'],
                'description' => $definition['description'] ?? null,
                'is_public' => $definition['is_public'],
                'is_locked' => $definition['is_locked'] ?? false,
                'sort_order' => $definition['sort_order'],
            ]);
        }

        // The service caches the whole payload; seeding behind its back would
        // otherwise leave stale data cached until the TTL lapsed.
        Cache::flush();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function definitions(): array
    {
        return [
            // ---------------------------------------------------------------
            // General — company identity
            // ---------------------------------------------------------------
            [
                'key' => 'general.company_name',
                'value' => 'Nexus Commerce',
                'type' => SettingType::String,
                'group' => SettingGroup::General,
                'label' => 'Company Name',
                'description' => 'Displayed in the header, page titles, and outbound email.',
                'is_public' => true,
                'is_locked' => true,
                'sort_order' => 1,
            ],
            [
                'key' => 'general.tagline',
                'value' => 'Everything you need, delivered.',
                'type' => SettingType::String,
                'group' => SettingGroup::General,
                'label' => 'Tagline',
                'is_public' => true,
                'sort_order' => 2,
            ],
            [
                'key' => 'general.description',
                'value' => 'A modern online store offering curated products with fast, reliable delivery.',
                'type' => SettingType::Text,
                'group' => SettingGroup::General,
                'label' => 'Company Description',
                'is_public' => true,
                'sort_order' => 3,
            ],
            [
                'key' => 'general.currency',
                'value' => 'USD',
                'type' => SettingType::String,
                'group' => SettingGroup::General,
                'label' => 'Default Currency',
                'is_public' => true,
                'sort_order' => 4,
            ],
            [
                'key' => 'general.currency_symbol',
                'value' => '$',
                'type' => SettingType::String,
                'group' => SettingGroup::General,
                'label' => 'Currency Symbol',
                'is_public' => true,
                'sort_order' => 5,
            ],
            [
                'key' => 'general.locale',
                'value' => 'en',
                'type' => SettingType::String,
                'group' => SettingGroup::General,
                'label' => 'Default Locale',
                'is_public' => true,
                'sort_order' => 6,
            ],
            [
                'key' => 'general.maintenance_mode',
                'value' => false,
                'type' => SettingType::Boolean,
                'group' => SettingGroup::General,
                'label' => 'Maintenance Mode',
                'description' => 'When enabled the storefront shows a maintenance notice.',
                'is_public' => true,
                'sort_order' => 7,
            ],

            // ---------------------------------------------------------------
            // Branding — assets
            // ---------------------------------------------------------------
            [
                'key' => 'branding.logo',
                'value' => null,
                'type' => SettingType::Image,
                'group' => SettingGroup::Branding,
                'label' => 'Logo',
                'description' => 'Primary logo shown in the site header. SVG or PNG recommended.',
                'is_public' => true,
                'is_locked' => true,
                'sort_order' => 1,
            ],
            [
                'key' => 'branding.logo_dark',
                'value' => null,
                'type' => SettingType::Image,
                'group' => SettingGroup::Branding,
                'label' => 'Logo (Dark Mode)',
                'is_public' => true,
                'sort_order' => 2,
            ],
            [
                'key' => 'branding.favicon',
                'value' => null,
                'type' => SettingType::Image,
                'group' => SettingGroup::Branding,
                'label' => 'Favicon',
                'description' => 'Browser tab icon. 32x32 ICO or PNG.',
                'is_public' => true,
                'is_locked' => true,
                'sort_order' => 3,
            ],
            [
                'key' => 'branding.og_image',
                'value' => null,
                'type' => SettingType::Image,
                'group' => SettingGroup::Branding,
                'label' => 'Social Share Image',
                'description' => 'Shown when a link to the site is shared. 1200x630 recommended.',
                'is_public' => true,
                'sort_order' => 4,
            ],

            // ---------------------------------------------------------------
            // Theme — colours applied as CSS custom properties
            // ---------------------------------------------------------------
            [
                'key' => 'theme.primary_color',
                'value' => '#2563eb',
                'type' => SettingType::Color,
                'group' => SettingGroup::Theme,
                'label' => 'Primary Colour',
                'description' => 'Buttons, links, and primary actions.',
                'is_public' => true,
                'is_locked' => true,
                'sort_order' => 1,
            ],
            [
                'key' => 'theme.secondary_color',
                'value' => '#64748b',
                'type' => SettingType::Color,
                'group' => SettingGroup::Theme,
                'label' => 'Secondary Colour',
                'is_public' => true,
                'sort_order' => 2,
            ],
            [
                'key' => 'theme.accent_color',
                'value' => '#f59e0b',
                'type' => SettingType::Color,
                'group' => SettingGroup::Theme,
                'label' => 'Accent Colour',
                'is_public' => true,
                'sort_order' => 3,
            ],
            [
                'key' => 'theme.background_color',
                'value' => '#ffffff',
                'type' => SettingType::Color,
                'group' => SettingGroup::Theme,
                'label' => 'Background Colour',
                'is_public' => true,
                'sort_order' => 4,
            ],
            [
                'key' => 'theme.foreground_color',
                'value' => '#0f172a',
                'type' => SettingType::Color,
                'group' => SettingGroup::Theme,
                'label' => 'Text Colour',
                'is_public' => true,
                'sort_order' => 5,
            ],
            [
                'key' => 'theme.destructive_color',
                'value' => '#dc2626',
                'type' => SettingType::Color,
                'group' => SettingGroup::Theme,
                'label' => 'Destructive Colour',
                'is_public' => true,
                'sort_order' => 6,
            ],
            [
                'key' => 'theme.radius',
                'value' => '0.5rem',
                'type' => SettingType::String,
                'group' => SettingGroup::Theme,
                'label' => 'Corner Radius',
                'is_public' => true,
                'sort_order' => 7,
            ],
            [
                'key' => 'theme.font_family',
                'value' => 'Inter',
                'type' => SettingType::String,
                'group' => SettingGroup::Theme,
                'label' => 'Font Family',
                'is_public' => true,
                'sort_order' => 8,
            ],

            // ---------------------------------------------------------------
            // Contact
            // ---------------------------------------------------------------
            [
                'key' => 'contact.email',
                'value' => 'support@example.com',
                'type' => SettingType::Email,
                'group' => SettingGroup::Contact,
                'label' => 'Support Email',
                'is_public' => true,
                'sort_order' => 1,
            ],
            [
                'key' => 'contact.phone',
                'value' => '+1 (555) 010-0100',
                'type' => SettingType::String,
                'group' => SettingGroup::Contact,
                'label' => 'Phone Number',
                'is_public' => true,
                'sort_order' => 2,
            ],
            [
                'key' => 'contact.address',
                'value' => '100 Market Street, Suite 200, San Francisco, CA 94103',
                'type' => SettingType::Text,
                'group' => SettingGroup::Contact,
                'label' => 'Business Address',
                'is_public' => true,
                'sort_order' => 3,
            ],
            [
                'key' => 'contact.support_hours',
                'value' => 'Monday to Friday, 9:00 - 18:00',
                'type' => SettingType::String,
                'group' => SettingGroup::Contact,
                'label' => 'Support Hours',
                'is_public' => true,
                'sort_order' => 4,
            ],

            // ---------------------------------------------------------------
            // Social
            // ---------------------------------------------------------------
            [
                'key' => 'social.facebook',
                'value' => null,
                'type' => SettingType::Url,
                'group' => SettingGroup::Social,
                'label' => 'Facebook URL',
                'is_public' => true,
                'sort_order' => 1,
            ],
            [
                'key' => 'social.instagram',
                'value' => null,
                'type' => SettingType::Url,
                'group' => SettingGroup::Social,
                'label' => 'Instagram URL',
                'is_public' => true,
                'sort_order' => 2,
            ],
            [
                'key' => 'social.x',
                'value' => null,
                'type' => SettingType::Url,
                'group' => SettingGroup::Social,
                'label' => 'X (Twitter) URL',
                'is_public' => true,
                'sort_order' => 3,
            ],
            [
                'key' => 'social.linkedin',
                'value' => null,
                'type' => SettingType::Url,
                'group' => SettingGroup::Social,
                'label' => 'LinkedIn URL',
                'is_public' => true,
                'sort_order' => 4,
            ],
            [
                'key' => 'social.youtube',
                'value' => null,
                'type' => SettingType::Url,
                'group' => SettingGroup::Social,
                'label' => 'YouTube URL',
                'is_public' => true,
                'sort_order' => 5,
            ],

            // ---------------------------------------------------------------
            // SEO
            // ---------------------------------------------------------------
            [
                'key' => 'seo.meta_title',
                'value' => 'Nexus Commerce — Everything you need, delivered.',
                'type' => SettingType::String,
                'group' => SettingGroup::Seo,
                'label' => 'Default Meta Title',
                'is_public' => true,
                'sort_order' => 1,
            ],
            [
                'key' => 'seo.meta_description',
                'value' => 'Shop curated products with fast, reliable delivery and easy returns.',
                'type' => SettingType::Text,
                'group' => SettingGroup::Seo,
                'label' => 'Default Meta Description',
                'is_public' => true,
                'sort_order' => 2,
            ],
            [
                'key' => 'seo.meta_keywords',
                'value' => 'ecommerce, online shopping, retail',
                'type' => SettingType::String,
                'group' => SettingGroup::Seo,
                'label' => 'Meta Keywords',
                'is_public' => true,
                'sort_order' => 3,
            ],
            [
                'key' => 'seo.indexable',
                'value' => true,
                'type' => SettingType::Boolean,
                'group' => SettingGroup::Seo,
                'label' => 'Allow Search Engine Indexing',
                'description' => 'Disable on staging environments to keep them out of search results.',
                'is_public' => true,
                'sort_order' => 4,
            ],

            // ---------------------------------------------------------------
            // Feature flags
            // ---------------------------------------------------------------
            [
                'key' => 'feature.wishlist_enabled',
                'value' => true,
                'type' => SettingType::Boolean,
                'group' => SettingGroup::Feature,
                'label' => 'Enable Wishlist',
                'is_public' => true,
                'sort_order' => 1,
            ],
            [
                'key' => 'feature.reviews_enabled',
                'value' => true,
                'type' => SettingType::Boolean,
                'group' => SettingGroup::Feature,
                'label' => 'Enable Product Reviews',
                'is_public' => true,
                'sort_order' => 2,
            ],
            [
                'key' => 'feature.guest_checkout_enabled',
                'value' => true,
                'type' => SettingType::Boolean,
                'group' => SettingGroup::Feature,
                'label' => 'Enable Guest Checkout',
                'is_public' => true,
                'sort_order' => 3,
            ],

            // ---------------------------------------------------------------
            // Mail — private, never exposed to the storefront
            // ---------------------------------------------------------------
            [
                'key' => 'mail.from_name',
                'value' => 'Nexus Commerce',
                'type' => SettingType::String,
                'group' => SettingGroup::Mail,
                'label' => 'Outbound Sender Name',
                'is_public' => false,
                'sort_order' => 1,
            ],
            [
                'key' => 'mail.from_address',
                'value' => 'noreply@example.com',
                'type' => SettingType::Email,
                'group' => SettingGroup::Mail,
                'label' => 'Outbound Sender Address',
                'is_public' => false,
                'sort_order' => 2,
            ],
        ];
    }
}
