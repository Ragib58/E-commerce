<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\BannerPlacement;
use App\Enums\PublishStatus;
use App\Enums\RoleType;
use App\Enums\SectionType;
use App\Enums\TokenAbility;
use App\Models\Admin;
use App\Models\Banner;
use App\Models\CmsPage;
use App\Models\HomepageSection;
use App\Models\Product;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The content admin surface: the homepage builder, banners, and CMS pages.
 */
final class ContentManagementTest extends TestCase
{
    use RefreshDatabase;

    private Admin $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->app->make('cache')->flush();

        $this->superAdmin = Admin::factory()->withRole(RoleType::SuperAdmin)->create();
    }

    private function asSuperAdmin(): self
    {
        $token = $this->superAdmin->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken;

        return $this->withToken($token);
    }

    private function asRole(RoleType $role): self
    {
        $admin = Admin::factory()->withRole($role)->create();
        $token = $admin->createToken('t', [TokenAbility::AdminAccess->value])->plainTextToken;

        return $this->withToken($token);
    }

    /*
    |--------------------------------------------------------------------------
    | Homepage builder
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_builder_returns_disabled_and_expired_sections(): void
    {
        HomepageSection::factory()->ofType(SectionType::FeaturedProducts)->disabled()->create(['name' => 'Off']);
        HomepageSection::factory()->ofType(SectionType::ProductCollection)->expired()->create(['name' => 'Lapsed']);

        $names = array_column(
            $this->asSuperAdmin()->getJson('/api/v1/admin/homepage/sections')->assertOk()->json('data'),
            'name',
        );

        // An operator cannot edit what the panel will not show them — and a
        // section that has silently expired is the one they came to find.
        $this->assertContains('Off', $names);
        $this->assertContains('Lapsed', $names);
    }

    #[Test]
    public function the_builder_serves_the_section_type_catalogue(): void
    {
        $response = $this->asSuperAdmin()->getJson('/api/v1/admin/homepage/sections')->assertOk();

        $types = array_column($response->json('meta.available_types'), 'value');

        // The panel's "add section" menu comes from here rather than a
        // hardcoded frontend list, so a new type needs no frontend change.
        $this->assertEqualsCanonicalizing(SectionType::values(), $types);
    }

    #[Test]
    public function an_administrator_can_add_a_section(): void
    {
        $this->asSuperAdmin()
            ->postJson('/api/v1/admin/homepage/sections', [
                'type' => SectionType::BestSellers->value,
                'name' => 'Top sellers',
                'heading' => 'Best sellers',
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'best_sellers')
            // The type's defaults are merged in on creation, so the section
            // renders sensibly before anyone opens its settings.
            ->assertJsonPath('data.settings.limit', 8);

        $this->assertDatabaseHas('homepage_sections', ['name' => 'Top sellers']);
    }

    #[Test]
    public function a_non_repeatable_section_type_cannot_be_added_twice(): void
    {
        HomepageSection::factory()->ofType(SectionType::BestSellers)->create();

        // Two "Best sellers" rails would render identical content twice, which
        // is a configuration mistake rather than a design.
        $this->asSuperAdmin()
            ->postJson('/api/v1/admin/homepage/sections', [
                'type' => SectionType::BestSellers->value,
                'name' => 'Another one',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    #[Test]
    public function a_repeatable_section_type_can_be_added_more_than_once(): void
    {
        HomepageSection::factory()->ofType(SectionType::CustomContent)->create();

        $this->asSuperAdmin()
            ->postJson('/api/v1/admin/homepage/sections', [
                'type' => SectionType::CustomContent->value,
                'name' => 'Second block',
            ])
            ->assertCreated();
    }

    #[Test]
    public function updating_a_section_merges_settings_rather_than_replacing_them(): void
    {
        $section = HomepageSection::factory()
            ->ofType(SectionType::Testimonials, [
                'columns' => 3,
                'items' => [['quote' => 'Excellent service.', 'author' => 'Sam']],
            ])
            ->create();

        // A scheduling form that submitted only `columns` must not wipe the
        // testimonials stored alongside it.
        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/homepage/sections/{$section->id}", [
                'settings' => ['columns' => 2],
            ])
            ->assertOk()
            ->assertJsonPath('data.settings.columns', 2);

        $section->refresh();

        $this->assertCount(1, $section->settings['items']);
    }

    #[Test]
    public function sections_can_be_reordered_in_one_request(): void
    {
        $first = HomepageSection::factory()->ofType(SectionType::HeroSlider)->create(['sort_order' => 0]);
        $second = HomepageSection::factory()->ofType(SectionType::BestSellers)->create(['sort_order' => 10]);

        $this->asSuperAdmin()
            ->putJson('/api/v1/admin/homepage/sections/reorder', [
                'items' => [
                    ['id' => $second->id, 'sort_order' => 0],
                    ['id' => $first->id, 'sort_order' => 10],
                ],
            ])
            ->assertOk();

        $this->assertSame(0, $second->refresh()->sort_order);
        $this->assertSame(10, $first->refresh()->sort_order);
    }

    #[Test]
    public function a_section_can_be_toggled_without_touching_its_other_fields(): void
    {
        $section = HomepageSection::factory()
            ->ofType(SectionType::FeaturedProducts)
            ->create(['heading' => 'Featured']);

        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/homepage/sections/{$section->id}/status", ['is_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.is_enabled', false)
            // The toggle is a separate endpoint precisely so it cannot carry —
            // and overwrite — the rest of the form.
            ->assertJsonPath('data.heading', 'Featured');
    }

    #[Test]
    public function a_section_reports_whether_it_is_live_as_well_as_enabled(): void
    {
        $section = HomepageSection::factory()
            ->ofType(SectionType::FeaturedProducts)
            ->scheduled()
            ->create();

        $this->asSuperAdmin()
            ->getJson("/api/v1/admin/homepage/sections/{$section->id}")
            ->assertOk()
            // Enabled and yet invisible: the distinction the panel must show,
            // and the reason it is computed server-side.
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.is_live', false)
            ->assertJsonPath('data.window_state', 'scheduled');
    }

    #[Test]
    public function a_schedule_that_ends_before_it_starts_is_rejected(): void
    {
        $section = HomepageSection::factory()->ofType(SectionType::FeaturedProducts)->create();

        // A window that closes before it opens produces a section that silently
        // never appears — the hardest kind of configuration bug to notice.
        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/homepage/sections/{$section->id}", [
                'starts_at' => Carbon::now()->addWeek()->toIso8601String(),
                'ends_at' => Carbon::now()->addDay()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ends_at');
    }

    #[Test]
    public function a_partial_update_validates_the_end_date_against_the_stored_start(): void
    {
        $section = HomepageSection::factory()
            ->ofType(SectionType::FeaturedProducts)
            ->create(['starts_at' => Carbon::now()->addWeek()]);

        // Only `ends_at` is sent, so an `after:starts_at` rule would pass
        // vacuously — the comparison has to happen against the merged state.
        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/homepage/sections/{$section->id}", [
                'ends_at' => Carbon::now()->addDay()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ends_at');
    }

    #[Test]
    public function the_preview_renders_the_homepage_at_an_arbitrary_moment(): void
    {
        Product::factory()->published()->create(['is_featured' => true]);

        HomepageSection::factory()
            ->ofType(SectionType::FeaturedProducts)
            ->create([
                'name' => 'Black Friday',
                'starts_at' => Carbon::now()->addWeek(),
            ]);

        $now = $this->asSuperAdmin()->getJson('/api/v1/admin/homepage/preview')->assertOk();
        $this->assertSame([], $now->json('data'));

        // Scheduling that can only be verified by waiting for the scheduled
        // moment is scheduling nobody trusts.
        $later = $this->asSuperAdmin()
            ->getJson('/api/v1/admin/homepage/preview?at=' . urlencode(Carbon::now()->addWeeks(2)->toIso8601String()))
            ->assertOk();

        $this->assertSame(['Black Friday'], array_column($later->json('data'), 'name'));
    }

    #[Test]
    public function custom_content_html_is_sanitised_on_write(): void
    {
        $section = HomepageSection::factory()->ofType(SectionType::CustomContent)->create();

        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/homepage/sections/{$section->id}", [
                'settings' => [
                    'content' => '<p>Safe copy</p><script>alert(1)</script><a href="javascript:alert(2)">x</a>',
                ],
            ])
            ->assertOk();

        $stored = (string) $section->refresh()->settings['content'];

        // Sanitised on write, so the *stored* value is the safe value and no
        // read path can bypass the filter.
        $this->assertStringContainsString('Safe copy', $stored);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
    }

    /*
    |--------------------------------------------------------------------------
    | Banners
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_administrator_can_create_a_banner_with_an_image(): void
    {
        Storage::fake('public');

        $this->asSuperAdmin()
            ->post('/api/v1/admin/banners', [
                'title' => 'Summer sale',
                'placement' => BannerPlacement::HeroSlider->value,
                'status' => PublishStatus::Published->value,
                'image' => UploadedFile::fake()->image('hero.jpg', 1600, 600),
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Summer sale');

        $this->assertDatabaseHas('banners', ['title' => 'Summer sale']);
    }

    #[Test]
    public function a_banner_requires_an_image_on_creation(): void
    {
        // A banner with no image has nothing to render.
        $this->asSuperAdmin()
            ->postJson('/api/v1/admin/banners', [
                'title' => 'No artwork',
                'placement' => BannerPlacement::HeroSlider->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    #[Test]
    public function a_banner_can_be_edited_without_resubmitting_its_image(): void
    {
        $banner = Banner::factory()->create(['title' => 'Original']);

        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/banners/{$banner->id}", ['title' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Renamed');

        // The existing file is untouched when the key is absent.
        $this->assertSame($banner->image, $banner->refresh()->image);
    }

    #[Test]
    public function a_javascript_link_is_rejected_on_a_banner(): void
    {
        // `url` validation alone would admit this, and it is a stored XSS
        // payload the moment it reaches an href.
        $this->asSuperAdmin()
            ->postJson('/api/v1/admin/banners', [
                'title' => 'Malicious',
                'placement' => BannerPlacement::HeroSlider->value,
                'link_url' => 'javascript:alert(1)',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('link_url');
    }

    #[Test]
    public function the_admin_banner_payload_includes_status_and_scheduling(): void
    {
        Banner::factory()->scheduled()->create();

        $this->asSuperAdmin()
            ->getJson('/api/v1/admin/banners')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'scheduled')
            ->assertJsonPath('data.0.window_state', 'scheduled')
            ->assertJsonPath('data.0.is_live', false);
    }

    /*
    |--------------------------------------------------------------------------
    | CMS pages
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function an_administrator_can_create_a_page(): void
    {
        $this->asSuperAdmin()
            ->postJson('/api/v1/admin/pages', [
                'title' => 'About Us',
                'content' => '<p>We started in a garage.</p>',
                'status' => PublishStatus::Published->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'about-us');

        $this->assertDatabaseHas('cms_pages', ['slug' => 'about-us']);
    }

    #[Test]
    public function page_content_is_sanitised_on_write(): void
    {
        $this->asSuperAdmin()
            ->postJson('/api/v1/admin/pages', [
                'title' => 'Policy',
                'content' => '<p onclick="steal()">Hello</p><script>alert(1)</script>',
            ])
            ->assertCreated();

        $stored = (string) CmsPage::query()->where('slug', 'policy')->value('content');

        $this->assertStringContainsString('Hello', $stored);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onclick', $stored);
    }

    #[Test]
    public function a_reserved_slug_is_refused(): void
    {
        // A page slugged "products" would be unreachable behind the
        // storefront's own route, and diagnosing that costs far more than
        // refusing it here.
        $this->asSuperAdmin()
            ->postJson('/api/v1/admin/pages', ['title' => 'Products', 'slug' => 'products'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('slug');
    }

    #[Test]
    public function renaming_a_page_does_not_change_its_slug(): void
    {
        $page = CmsPage::factory()->create(['slug' => 'refund-policy', 'title' => 'Refund Policy']);

        // Reslugging on a rename would break every inbound link, bookmark, and
        // past order email pointing at the old URL.
        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/pages/{$page->slug}", ['title' => 'Returns & Refunds'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'refund-policy')
            ->assertJsonPath('data.title', 'Returns & Refunds');
    }

    #[Test]
    public function a_system_page_cannot_be_deleted_but_can_be_edited(): void
    {
        $page = CmsPage::factory()->system()->create(['slug' => 'privacy-policy']);

        $this->asSuperAdmin()
            ->deleteJson("/api/v1/admin/pages/{$page->slug}")
            ->assertStatus(422);

        $this->assertDatabaseHas('cms_pages', ['slug' => 'privacy-policy', 'deleted_at' => null]);

        // `is_system` is a delete guard, not a read-only flag: a store must be
        // able to write its own privacy policy.
        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/pages/{$page->slug}", ['content' => '<p>Our policy.</p>'])
            ->assertOk();
    }

    #[Test]
    public function an_ordinary_page_can_be_deleted(): void
    {
        $page = CmsPage::factory()->create(['slug' => 'size-guide']);

        $this->asSuperAdmin()
            ->deleteJson("/api/v1/admin/pages/{$page->slug}")
            ->assertOk();

        $this->assertSoftDeleted('cms_pages', ['slug' => 'size-guide']);
    }

    #[Test]
    public function publishing_stamps_published_at_only_once(): void
    {
        $page = CmsPage::factory()->draft()->create(['slug' => 'terms']);

        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/pages/{$page->slug}/status", ['status' => 'published'])
            ->assertOk();

        $firstPublishedAt = $page->refresh()->published_at;

        $this->assertNotNull($firstPublishedAt);

        Carbon::setTestNow(Carbon::now()->addDays(3));

        $this->asSuperAdmin()
            ->patchJson("/api/v1/admin/pages/{$page->slug}", ['content' => '<p>Fixed a typo.</p>'])
            ->assertOk();

        // A policy page's effective date must not move because someone fixed a
        // typo — `updated_at` covers edits.
        $this->assertTrue($firstPublishedAt->equalTo($page->refresh()->published_at));

        Carbon::setTestNow();
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function content_endpoints_reject_an_unauthenticated_request(): void
    {
        $this->getJson('/api/v1/admin/homepage/sections')->assertUnauthorized();
        $this->getJson('/api/v1/admin/banners')->assertUnauthorized();
        $this->getJson('/api/v1/admin/pages')->assertUnauthorized();
    }

    #[Test]
    public function a_content_manager_can_build_the_homepage(): void
    {
        $this->asRole(RoleType::ContentManager)
            ->postJson('/api/v1/admin/homepage/sections', [
                'type' => SectionType::CustomContent->value,
                'name' => 'Notice',
            ])
            ->assertCreated();
    }

    #[Test]
    public function a_product_manager_cannot_restructure_the_homepage(): void
    {
        // Catalog permissions do not confer editorial ones.
        $this->asRole(RoleType::ProductManager)
            ->postJson('/api/v1/admin/homepage/sections', [
                'type' => SectionType::CustomContent->value,
                'name' => 'Notice',
            ])
            ->assertForbidden();
    }
}
