<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\SettingGroup;
use App\Enums\SettingType;
use App\Models\Setting;
use App\Services\SettingsService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PublicSettingsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_seeded_branding_grouped_by_setting_group(): void
    {
        $this->seed(SettingsSeeder::class);

        $response = $this->getJson('/api/v1/settings/public');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'general' => ['company_name', 'tagline'],
                    'branding' => ['logo', 'logo_light', 'logo_dark', 'favicon', 'brand_description'],
                    'theme' => ['primary_color', 'secondary_color', 'accent_color', 'button_color', 'radius', 'font_family'],
                    'contact' => ['email', 'phone', 'address', 'google_maps_url'],
                    'social' => ['facebook', 'instagram', 'youtube', 'linkedin', 'tiktok'],
                    'seo' => ['website_title', 'meta_description', 'meta_keywords'],
                    'analytics' => ['google_analytics_id', 'facebook_pixel_id'],
                    'business' => ['currency', 'currency_symbol', 'tax_rate', 'vat_rate', 'order_prefix', 'invoice_prefix'],
                ],
                'meta' => ['version', 'groups'],
            ]);

        // Keys are returned without their group prefix so the frontend can
        // consume `data.branding.logo` rather than `data.branding['branding.logo']`.
        $response->assertJsonPath('data.general.company_name', 'Nexus Commerce');
        $response->assertJsonPath('data.theme.primary_color', '#2563eb');
        $response->assertJsonPath('data.business.currency', 'USD');
    }

    #[Test]
    public function private_settings_are_never_exposed(): void
    {
        $this->seed(SettingsSeeder::class);

        $response = $this->getJson('/api/v1/settings/public');

        // `mail` is a non-public group and must be absent entirely.
        $response->assertJsonMissingPath('data.mail');

        $this->assertArrayNotHasKey('mail', $response->json('data'));
    }

    #[Test]
    public function a_private_group_is_excluded_even_when_flagged_public(): void
    {
        // Defence in depth: `is_public` alone must not be sufficient to leak a
        // payment credential, because the group is not publicly exposable.
        Setting::factory()->create([
            'key' => 'payment.stripe_secret',
            'value' => 'sk_live_must_not_leak',
            'type' => SettingType::String,
            'group' => SettingGroup::Payment,
            'is_public' => true,
        ]);

        $response = $this->getJson('/api/v1/settings/public');

        $response->assertOk();
        $this->assertStringNotContainsString('sk_live_must_not_leak', $response->getContent() ?: '');
        $this->assertArrayNotHasKey('payment', $response->json('data'));
    }

    #[Test]
    public function values_are_returned_cast_to_their_declared_type(): void
    {
        Setting::factory()->create([
            'key' => 'feature.reviews_enabled',
            'value' => '1',
            'type' => SettingType::Boolean,
            'group' => SettingGroup::Feature,
            'is_public' => true,
        ]);

        Setting::factory()->create([
            'key' => 'general.items_per_page',
            'value' => '24',
            'type' => SettingType::Integer,
            'group' => SettingGroup::General,
            'is_public' => true,
        ]);

        $response = $this->getJson('/api/v1/settings/public');

        // Strict comparison: a string "1" would break `if (settings.enabled)`
        // semantics on the frontend for the value "0".
        $this->assertTrue($response->json('data.feature.reviews_enabled'));
        $this->assertSame(24, $response->json('data.general.items_per_page'));
    }

    #[Test]
    public function it_can_be_narrowed_to_a_single_group(): void
    {
        $this->seed(SettingsSeeder::class);

        $response = $this->getJson('/api/v1/settings/public?group=theme');

        $response
            ->assertOk()
            ->assertJsonPath('meta.groups', ['theme']);

        $this->assertArrayNotHasKey('branding', $response->json('data'));
    }

    #[Test]
    public function requesting_a_non_public_group_is_rejected_with_validation_errors(): void
    {
        $response = $this->getJson('/api/v1/settings/public?group=payment');

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['group']]);
    }

    #[Test]
    public function updating_a_setting_invalidates_the_cached_payload(): void
    {
        $this->seed(SettingsSeeder::class);

        // Prime the cache.
        $this->getJson('/api/v1/settings/public')
            ->assertJsonPath('data.general.company_name', 'Nexus Commerce');

        app(SettingsService::class)->set('general.company_name', 'Renamed Store');

        // A stale cache here would serve the old name — this asserts the
        // event-driven invalidation actually fires.
        $this->getJson('/api/v1/settings/public')
            ->assertJsonPath('data.general.company_name', 'Renamed Store');
    }

    #[Test]
    public function the_version_stamp_changes_when_settings_change(): void
    {
        $this->seed(SettingsSeeder::class);

        $before = $this->getJson('/api/v1/settings/public')->json('meta.version');

        app(SettingsService::class)->set('general.tagline', 'A new tagline');

        $after = $this->getJson('/api/v1/settings/public')->json('meta.version');

        $this->assertNotSame($before, $after);
    }
}
