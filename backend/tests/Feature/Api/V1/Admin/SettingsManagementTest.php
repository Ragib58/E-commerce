<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\PermissionType;
use App\Enums\RoleType;
use App\Enums\SettingGroup;
use App\Enums\SettingType;
use App\Enums\TokenAbility;
use App\Models\Admin;
use App\Models\Setting;
use App\Services\SettingsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Admin-facing settings management: reading the editable payload, bulk writes,
 * brand asset upload/removal, and the permission boundary around all of it.
 */
final class SettingsManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->app->make('cache')->flush();
    }

    private function tokenFor(Admin $admin): string
    {
        return $admin->createToken('test', [TokenAbility::AdminAccess->value])->plainTextToken;
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(Admin $admin): array
    {
        return ['Authorization' => 'Bearer '.$this->tokenFor($admin)];
    }

    private function adminWithSettingsAccess(): Admin
    {
        return Admin::factory()->withRole(RoleType::SuperAdmin)->create();
    }

    // -----------------------------------------------------------------------
    // Access control
    // -----------------------------------------------------------------------

    #[Test]
    public function unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/admin/settings')->assertUnauthorized();
        $this->putJson('/api/v1/admin/settings', ['settings' => []])->assertUnauthorized();
    }

    #[Test]
    public function an_admin_without_settings_permission_is_forbidden(): void
    {
        // Support staff act on tickets but must not be able to restyle the
        // storefront or read private mail/payment configuration.
        $admin = Admin::factory()->withRole(RoleType::SupportStaff)->create();

        $this->assertFalse($admin->hasPermission(PermissionType::ViewSettings));
        $this->assertFalse($admin->hasPermission(PermissionType::ManageSettings));

        $this->withHeaders($this->authHeaders($admin))
            ->getJson('/api/v1/admin/settings')
            ->assertForbidden();
    }

    #[Test]
    public function a_read_only_admin_can_view_but_not_write(): void
    {
        // Manager is seeded with view_settings but not manage_settings — the
        // exact split the two middleware lists exist to enforce. Super Admin
        // cannot be used here: it bypasses permission checks entirely.
        $admin = Admin::factory()->withRole(RoleType::Manager)->create();

        $this->assertTrue($admin->hasPermission(PermissionType::ViewSettings));
        $this->assertFalse($admin->hasPermission(PermissionType::ManageSettings));

        $headers = $this->authHeaders($admin);

        $this->withHeaders($headers)->getJson('/api/v1/admin/settings')->assertOk();

        $this->withHeaders($headers)
            ->putJson('/api/v1/admin/settings', [
                'settings' => ['theme.primary_color' => '#111111'],
            ])
            ->assertForbidden();

        // The refused write must not have landed.
        $this->assertSame('#2563eb', app(SettingsService::class)->get('theme.primary_color'));
    }

    // -----------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------

    #[Test]
    public function it_returns_every_group_including_private_ones(): void
    {
        $response = $this->withHeaders($this->authHeaders($this->adminWithSettingsAccess()))
            ->getJson('/api/v1/admin/settings');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'branding' => ['label', 'description', 'is_public', 'settings'],
                ],
                'meta' => ['version', 'groups'],
            ]);

        // Unlike the public endpoint, mail configuration is visible here.
        $this->assertArrayHasKey('mail', $response->json('data'));
        $this->assertFalse($response->json('data.mail.is_public'));
    }

    #[Test]
    public function it_exposes_the_metadata_needed_to_render_a_form(): void
    {
        $response = $this->withHeaders($this->authHeaders($this->adminWithSettingsAccess()))
            ->getJson('/api/v1/admin/settings?group=theme');

        $response->assertOk()->assertJsonPath('meta.groups', ['theme']);

        $settings = collect($response->json('data.theme.settings'));
        $primary = $settings->firstWhere('key', 'theme.primary_color');

        $this->assertSame('color', $primary['type']);
        $this->assertSame('Primary Colour', $primary['label']);
        $this->assertTrue($primary['is_locked']);
    }

    #[Test]
    public function the_groups_endpoint_lists_every_group(): void
    {
        $response = $this->withHeaders($this->authHeaders($this->adminWithSettingsAccess()))
            ->getJson('/api/v1/admin/settings/groups');

        $response->assertOk();

        $values = array_column($response->json('data'), 'value');

        $this->assertContains('business', $values);
        $this->assertContains('analytics', $values);
        $this->assertCount(count(SettingGroup::cases()), $values);
    }

    // -----------------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------------

    #[Test]
    public function it_updates_settings_and_reflects_them_on_the_public_endpoint(): void
    {
        $this->withHeaders($this->authHeaders($this->adminWithSettingsAccess()))
            ->putJson('/api/v1/admin/settings', [
                'settings' => [
                    'general.company_name' => 'Aurora Supply',
                    'theme.primary_color' => '#7c3aed',
                    'business.currency_symbol' => '€',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        // The write must be visible to unauthenticated storefront clients
        // immediately — a stale cache here is the exact failure this whole
        // invalidation pipeline exists to prevent.
        $this->getJson('/api/v1/settings/public')
            ->assertJsonPath('data.general.company_name', 'Aurora Supply')
            ->assertJsonPath('data.theme.primary_color', '#7c3aed')
            ->assertJsonPath('data.business.currency_symbol', '€');
    }

    #[Test]
    public function it_preserves_declared_types_on_write(): void
    {
        $this->withHeaders($this->authHeaders($this->adminWithSettingsAccess()))
            ->putJson('/api/v1/admin/settings', [
                'settings' => [
                    'feature.reviews_enabled' => false,
                    'business.tax_rate' => 8.25,
                ],
            ])
            ->assertOk();

        $response = $this->getJson('/api/v1/settings/public');

        // Strict: a string "0" is truthy in JavaScript and would silently
        // re-enable the feature on the storefront.
        $this->assertFalse($response->json('data.feature.reviews_enabled'));
        $this->assertSame(8.25, $response->json('data.business.tax_rate'));
    }

    #[Test]
    public function an_invalid_colour_is_rejected_and_nothing_is_written(): void
    {
        $original = app(SettingsService::class)->get('theme.primary_color');

        $this->withHeaders($this->authHeaders($this->adminWithSettingsAccess()))
            ->putJson('/api/v1/admin/settings', [
                'settings' => [
                    'general.company_name' => 'Should Not Persist',
                    'theme.primary_color' => 'javascript:alert(1)',
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        // The whole submission is validated before anything is written, so a
        // single bad field cannot leave a half-applied theme.
        $this->assertSame($original, app(SettingsService::class)->get('theme.primary_color'));
        $this->assertSame('Nexus Commerce', app(SettingsService::class)->get('general.company_name'));
    }

    #[Test]
    public function an_unknown_key_is_rejected_rather_than_created(): void
    {
        $this->withHeaders($this->authHeaders($this->adminWithSettingsAccess()))
            ->putJson('/api/v1/admin/settings', [
                'settings' => ['payment.injected_secret' => 'sk_live_nope'],
            ])
            ->assertStatus(422);

        // Accepting unknown keys would turn this endpoint into an arbitrary
        // settings-row factory, including rows in publicly exposed groups.
        $this->assertDatabaseMissing('settings', ['key' => 'payment.injected_secret']);
    }

    // -----------------------------------------------------------------------
    // Media
    // -----------------------------------------------------------------------

    #[Test]
    public function it_uploads_a_logo_and_returns_its_url(): void
    {
        Storage::fake('public');

        $response = $this->withHeaders($this->authHeaders($this->adminWithSettingsAccess()))
            ->postJson('/api/v1/admin/settings/media/branding.logo', [
                'file' => UploadedFile::fake()->image('company-logo.png', 400, 120),
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.key', 'branding.logo')
            ->assertJsonStructure(['data' => ['key', 'url']]);

        $path = app(SettingsService::class)->rawValue('branding.logo');

        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        // The stored value is a relative path, never an absolute URL — moving
        // buckets or changing a CDN domain must not require rewriting rows.
        $this->assertStringStartsWith('branding/', $path);
        $this->assertStringNotContainsString('http', $path);
    }

    #[Test]
    public function each_brand_asset_slot_is_independently_uploadable(): void
    {
        Storage::fake('public');

        $headers = $this->authHeaders($this->adminWithSettingsAccess());

        foreach (['branding.logo', 'branding.logo_light', 'branding.logo_dark', 'branding.favicon'] as $key) {
            $this->withHeaders($headers)
                ->postJson("/api/v1/admin/settings/media/{$key}", [
                    'file' => UploadedFile::fake()->image('asset.png', 64, 64),
                ])
                ->assertCreated();
        }

        $settings = app(SettingsService::class);

        // Uploading one asset must not disturb the others.
        foreach (['branding.logo', 'branding.logo_light', 'branding.logo_dark', 'branding.favicon'] as $key) {
            $this->assertNotNull($settings->rawValue($key), "{$key} was not stored.");
        }
    }

    #[Test]
    public function replacing_an_asset_deletes_the_previous_file(): void
    {
        Storage::fake('public');

        $headers = $this->authHeaders($this->adminWithSettingsAccess());

        $this->withHeaders($headers)->postJson('/api/v1/admin/settings/media/branding.logo', [
            'file' => UploadedFile::fake()->image('first.png'),
        ])->assertCreated();

        $first = app(SettingsService::class)->rawValue('branding.logo');

        $this->withHeaders($headers)->postJson('/api/v1/admin/settings/media/branding.logo', [
            'file' => UploadedFile::fake()->image('second.png'),
        ])->assertCreated();

        $second = app(SettingsService::class)->rawValue('branding.logo');

        $this->assertNotSame($first, $second);

        // Otherwise every re-upload would leak a file into the bucket forever.
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    #[Test]
    public function removing_an_asset_clears_the_value_and_deletes_the_file(): void
    {
        Storage::fake('public');

        $headers = $this->authHeaders($this->adminWithSettingsAccess());

        $this->withHeaders($headers)->postJson('/api/v1/admin/settings/media/branding.favicon', [
            'file' => UploadedFile::fake()->image('favicon.png', 32, 32),
        ])->assertCreated();

        $path = app(SettingsService::class)->rawValue('branding.favicon');

        $this->withHeaders($headers)
            ->deleteJson('/api/v1/admin/settings/media/branding.favicon')
            ->assertOk()
            ->assertJsonPath('data.url', null);

        Storage::disk('public')->assertMissing($path);

        // The row survives with a null value: the key is part of the seeded
        // schema and the admin form still needs to render its field.
        $this->assertDatabaseHas('settings', ['key' => 'branding.favicon', 'value' => null]);
    }

    #[Test]
    public function a_non_image_upload_is_rejected(): void
    {
        Storage::fake('public');

        $this->withHeaders($this->authHeaders($this->adminWithSettingsAccess()))
            ->postJson('/api/v1/admin/settings/media/branding.logo', [
                'file' => UploadedFile::fake()->create('payload.php', 16, 'application/x-php'),
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['file']]);

        $this->assertNull(app(SettingsService::class)->rawValue('branding.logo'));
    }

    #[Test]
    public function an_upload_targeting_a_non_file_setting_is_rejected(): void
    {
        Storage::fake('public');

        // Without this guard a caller could store a file path where the
        // storefront expects a hex colour.
        $this->withHeaders($this->authHeaders($this->adminWithSettingsAccess()))
            ->postJson('/api/v1/admin/settings/media/theme.primary_color', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertStatus(422);

        $this->assertSame('#2563eb', app(SettingsService::class)->get('theme.primary_color'));
    }

    #[Test]
    public function an_uploaded_asset_is_served_as_an_absolute_url_to_the_storefront(): void
    {
        Storage::fake('public');

        $this->withHeaders($this->authHeaders($this->adminWithSettingsAccess()))
            ->postJson('/api/v1/admin/settings/media/branding.logo', [
                'file' => UploadedFile::fake()->image('logo.svg'),
            ])
            ->assertCreated();

        $logo = $this->getJson('/api/v1/settings/public')->json('data.branding.logo');
        $path = app(SettingsService::class)->rawValue('branding.logo');

        // The frontend puts this straight into an <img src>, so the API must
        // expand the stored path into a resolvable URL. The exact prefix is the
        // disk's business — Storage::fake() yields /storage/…, the real public
        // disk yields APP_URL/storage/…, S3 yields the bucket or CDN host — so
        // what is asserted is that expansion happened at all.
        $this->assertIsString($logo);
        $this->assertNotSame($path, $logo);
        $this->assertStringContainsString('/storage/', $logo);
        $this->assertStringEndsWith('.svg', $logo);
    }

    // -----------------------------------------------------------------------
    // Cache
    // -----------------------------------------------------------------------

    #[Test]
    public function flushing_the_cache_bumps_the_version_stamp(): void
    {
        $headers = $this->authHeaders($this->adminWithSettingsAccess());

        $before = $this->getJson('/api/v1/settings/public')->json('meta.version');

        $this->withHeaders($headers)
            ->postJson('/api/v1/admin/settings/cache/flush')
            ->assertOk();

        $after = $this->getJson('/api/v1/settings/public')->json('meta.version');

        $this->assertNotSame($before, $after);
    }

    #[Test]
    public function a_setting_added_at_runtime_appears_without_a_migration(): void
    {
        // The point of the EAV shape: a new brandable field is an INSERT.
        Setting::query()->create([
            'key' => 'social.threads',
            'value' => 'https://threads.net/@store',
            'type' => SettingType::Url,
            'group' => SettingGroup::Social,
            'label' => 'Threads URL',
            'is_public' => true,
        ]);

        app(SettingsService::class)->flush();

        $this->getJson('/api/v1/settings/public')
            ->assertJsonPath('data.social.threads', 'https://threads.net/@store');
    }
}
