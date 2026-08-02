<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Services\SettingsService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The server-rendered settings panel.
 *
 * These exercise the Blade views themselves — a broken template, a missing
 * variable, or an undefined route helper is invisible to the API tests and
 * would otherwise only surface in a browser.
 */
final class SettingsPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These are `web` routes, so every form POST carries a CSRF token that
        // a test client has no way to obtain. Only the CSRF middleware is
        // disabled — validation, redirects, and session flashing all still run,
        // which is what these assertions depend on.
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->seed(SettingsSeeder::class);
        $this->app->make('cache')->flush();
    }

    #[Test]
    public function the_settings_page_renders(): void
    {
        $response = $this->get('/admin/settings');

        $response
            ->assertOk()
            ->assertViewIs('admin.settings.index')
            ->assertSee('Store Settings')
            // Group tabs are generated from the enum.
            ->assertSee('Theme &amp; Colours', false)
            ->assertSee('Business Rules');
    }

    #[Test]
    public function each_group_renders_its_own_fields(): void
    {
        // Every group must render: a template error in one type's branch would
        // otherwise only appear once an admin opened that particular tab.
        foreach (['general', 'branding', 'theme', 'contact', 'social', 'seo', 'analytics', 'business', 'feature', 'mail'] as $group) {
            $this->get("/admin/settings?group={$group}")
                ->assertOk();
        }
    }

    #[Test]
    public function a_colour_field_renders_a_swatch_bound_to_its_text_input(): void
    {
        $response = $this->get('/admin/settings?group=theme');

        $response
            ->assertOk()
            ->assertSee('setting-theme-primary_color', false)
            ->assertSee('data-color-for="setting-theme-primary_color"', false)
            ->assertSee('name="settings[theme.primary_color]"', false);
    }

    #[Test]
    public function a_boolean_field_renders_a_hidden_companion(): void
    {
        $response = $this->get('/admin/settings?group=feature');

        // Without the hidden 0, an unchecked box submits nothing and the
        // toggle could never be turned off.
        $response
            ->assertOk()
            ->assertSee('type="hidden" name="settings[feature.wishlist_enabled]" value="0"', false);
    }

    #[Test]
    public function media_settings_render_upload_cards_outside_the_bulk_form(): void
    {
        $response = $this->get('/admin/settings?group=branding');

        $response
            ->assertOk()
            ->assertSee('Assets')
            ->assertSee('enctype="multipart/form-data"', false)
            // A file setting must not appear as a text input in the bulk form.
            ->assertDontSee('name="settings[branding.logo]"', false);
    }

    #[Test]
    public function an_unknown_group_falls_back_to_general(): void
    {
        $this->get('/admin/settings?group=not-a-group')
            ->assertOk()
            ->assertSee('Company Name');
    }

    #[Test]
    public function saving_a_group_updates_the_settings(): void
    {
        $response = $this->put('/admin/settings/theme', [
            'settings' => [
                'theme.primary_color' => '#7c3aed',
                'theme.font_family' => 'Roboto',
            ],
        ]);

        $response
            ->assertRedirect('/admin/settings?group=theme')
            ->assertSessionHas('status');

        $settings = app(SettingsService::class);

        $this->assertSame('#7c3aed', $settings->get('theme.primary_color'));
        $this->assertSame('Roboto', $settings->get('theme.font_family'));
    }

    #[Test]
    public function an_invalid_colour_is_rejected_with_a_field_error(): void
    {
        $response = $this->from('/admin/settings?group=theme')
            ->put('/admin/settings/theme', [
                'settings' => ['theme.primary_color' => 'not-a-colour'],
            ]);

        // The escaped-dot rule key is what makes this fire at all: unescaped,
        // the validator would read `settings.theme.primary_color` as nested
        // array traversal, find nothing, and pass silently.
        $response->assertSessionHasErrors('settings.theme.primary_color');

        $this->assertSame('#2563eb', app(SettingsService::class)->get('theme.primary_color'));
    }

    #[Test]
    public function an_unchecked_toggle_is_saved_as_false(): void
    {
        $this->assertTrue(app(SettingsService::class)->get('feature.wishlist_enabled'));

        // The browser omits an unchecked box entirely; only the hidden
        // companion arrives.
        $this->put('/admin/settings/feature', [
            'settings' => ['feature.wishlist_enabled' => '0'],
        ])->assertRedirect();

        $this->assertFalse(app(SettingsService::class)->get('feature.wishlist_enabled'));
    }

    #[Test]
    public function an_empty_value_is_stored_as_null_so_the_frontend_falls_back(): void
    {
        $this->put('/admin/settings/general', [
            'settings' => ['general.tagline' => ''],
        ])->assertRedirect();

        $this->assertNull(app(SettingsService::class)->get('general.tagline'));
    }

    #[Test]
    public function a_key_from_another_group_is_not_written(): void
    {
        // The theme form must not be able to rewrite a payment credential.
        $this->put('/admin/settings/theme', [
            'settings' => ['general.company_name' => 'Hijacked'],
        ])->assertRedirect();

        $this->assertSame('Nexus Commerce', app(SettingsService::class)->get('general.company_name'));
    }

    #[Test]
    public function an_unknown_group_cannot_be_saved(): void
    {
        $this->put('/admin/settings/not-a-group', ['settings' => []])
            ->assertNotFound();
    }

    #[Test]
    public function it_uploads_and_removes_a_brand_asset(): void
    {
        Storage::fake('local');

        $this->post('/admin/settings/media/branding.logo', [
            'file' => UploadedFile::fake()->image('logo.png'),
        ])
            ->assertRedirect('/admin/settings?group=branding')
            ->assertSessionHas('status');

        $path = app(SettingsService::class)->rawValue('branding.logo');
        $this->assertNotNull($path);

        $this->delete('/admin/settings/media/branding.logo')
            ->assertRedirect('/admin/settings?group=branding');

        $this->assertNull(app(SettingsService::class)->rawValue('branding.logo'));
    }

    #[Test]
    public function uploading_to_a_non_media_setting_is_refused(): void
    {
        Storage::fake('local');

        $this->post('/admin/settings/media/theme.primary_color', [
            'file' => UploadedFile::fake()->image('logo.png'),
        ])->assertNotFound();

        $this->assertSame('#2563eb', app(SettingsService::class)->get('theme.primary_color'));
    }

    #[Test]
    public function the_cache_can_be_flushed_from_the_panel(): void
    {
        $before = app(SettingsService::class)->version();

        $this->from('/admin/settings')
            ->post('/admin/settings/cache/flush')
            ->assertRedirect('/admin/settings')
            ->assertSessionHas('status');

        $this->assertNotSame($before, app(SettingsService::class)->version());
    }
}
