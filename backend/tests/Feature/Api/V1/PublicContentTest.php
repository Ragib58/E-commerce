<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\BannerPlacement;
use App\Enums\SectionType;
use App\Models\Banner;
use App\Models\Category;
use App\Models\CmsPage;
use App\Models\HomepageSection;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The public storefront content API.
 *
 * The assertions that matter most are the negative ones. Scheduling is only
 * meaningful if a section outside its window is genuinely unreachable — not
 * merely hidden by the frontend — so most of what follows checks that content
 * which should not be visible is absent from the response entirely.
 */
final class PublicContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('cache')->flush();
    }

    /*
    |--------------------------------------------------------------------------
    | Homepage assembly
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_homepage_returns_enabled_sections_in_sort_order(): void
    {
        Product::factory()->published()->create(['is_featured' => true]);

        HomepageSection::factory()
            ->ofType(SectionType::FeaturedProducts)
            ->create(['name' => 'Second', 'sort_order' => 20]);

        HomepageSection::factory()
            ->ofType(SectionType::CustomContent, ['content' => '<p>First</p>'])
            ->create(['name' => 'First', 'sort_order' => 10]);

        $response = $this->getJson('/api/v1/homepage')->assertOk();

        $names = array_column($response->json('data'), 'name');

        $this->assertSame(['First', 'Second'], $names);
    }

    #[Test]
    public function a_disabled_section_is_absent_from_the_homepage(): void
    {
        Product::factory()->published()->create(['is_featured' => true]);

        HomepageSection::factory()
            ->ofType(SectionType::FeaturedProducts)
            ->disabled()
            ->create(['name' => 'Hidden rail']);

        $response = $this->getJson('/api/v1/homepage')->assertOk();

        $this->assertNotContains('Hidden rail', array_column($response->json('data'), 'name'));
    }

    #[Test]
    public function a_section_outside_its_schedule_is_absent_from_the_homepage(): void
    {
        Product::factory()->published()->create(['is_featured' => true]);

        HomepageSection::factory()
            ->ofType(SectionType::FeaturedProducts)
            ->scheduled()
            ->create(['name' => 'Not yet']);

        HomepageSection::factory()
            ->ofType(SectionType::ProductCollection)
            ->expired()
            ->create(['name' => 'Already over']);

        $names = array_column($this->getJson('/api/v1/homepage')->assertOk()->json('data'), 'name');

        $this->assertNotContains('Not yet', $names);
        $this->assertNotContains('Already over', $names);
    }

    #[Test]
    public function a_section_becomes_visible_once_its_window_opens(): void
    {
        Product::factory()->published()->create(['is_featured' => true]);

        HomepageSection::factory()
            ->ofType(SectionType::FeaturedProducts)
            ->create([
                'name' => 'Launch day',
                'starts_at' => Carbon::now()->addHour(),
            ]);

        $this->assertNotContains(
            'Launch day',
            array_column($this->getJson('/api/v1/homepage')->json('data'), 'name'),
        );

        // The window is evaluated in SQL against "now", so moving the clock is
        // sufficient — nothing needs re-saving for the section to appear.
        Carbon::setTestNow(Carbon::now()->addHours(2));
        $this->app->make('cache')->flush();

        $this->assertContains(
            'Launch day',
            array_column($this->getJson('/api/v1/homepage')->json('data'), 'name'),
        );

        Carbon::setTestNow();
    }

    #[Test]
    public function a_section_whose_content_resolves_empty_is_dropped(): void
    {
        // No featured products exist, so the rail has nothing to show. An empty
        // heading above a blank strip reads as a broken page, so the section is
        // omitted rather than sent with an empty items array.
        HomepageSection::factory()
            ->ofType(SectionType::FeaturedProducts)
            ->create(['name' => 'Empty rail']);

        $response = $this->getJson('/api/v1/homepage')->assertOk();

        $this->assertSame([], $response->json('data'));
        $this->assertFalse($response->json('meta.is_configured'));
    }

    #[Test]
    public function a_custom_content_section_survives_with_no_items(): void
    {
        // Unlike a catalog rail, a custom-content block *is* its own content —
        // it has no items and is still perfectly renderable.
        HomepageSection::factory()
            ->ofType(SectionType::CustomContent, ['content' => '<p>Free shipping over £50.</p>'])
            ->create(['name' => 'Shipping promise']);

        $response = $this->getJson('/api/v1/homepage')->assertOk();

        $this->assertContains('Shipping promise', array_column($response->json('data'), 'name'));
    }

    #[Test]
    public function a_hero_section_resolves_only_live_banners_for_its_placement(): void
    {
        Banner::factory()->placement(BannerPlacement::HeroSlider)->create(['title' => 'Live slide']);
        Banner::factory()->placement(BannerPlacement::HeroSlider)->draft()->create(['title' => 'Draft slide']);
        Banner::factory()->placement(BannerPlacement::HeroSlider)->expired()->create(['title' => 'Old campaign']);
        Banner::factory()->placement(BannerPlacement::Checkout)->create(['title' => 'Checkout strip']);

        HomepageSection::factory()->ofType(SectionType::HeroSlider)->create();

        $response = $this->getJson('/api/v1/homepage')->assertOk();

        $titles = array_column($response->json('data.0.items'), 'title');

        $this->assertSame(['Live slide'], $titles);
    }

    #[Test]
    public function a_product_collection_preserves_the_curated_order(): void
    {
        $first = Product::factory()->published()->create(['name' => 'Alpha']);
        $second = Product::factory()->published()->create(['name' => 'Beta']);
        $third = Product::factory()->published()->create(['name' => 'Gamma']);

        // Deliberately not ascending by id: a whereIn returns rows in index
        // order, which would silently discard the merchandising sequence.
        HomepageSection::factory()
            ->ofType(SectionType::ProductCollection, [
                'product_ids' => [$third->id, $first->id, $second->id],
            ])
            ->create();

        $response = $this->getJson('/api/v1/homepage')->assertOk();

        $this->assertSame(
            ['Gamma', 'Alpha', 'Beta'],
            array_column($response->json('data.0.items'), 'name'),
        );
    }

    #[Test]
    public function a_collection_silently_drops_a_product_that_is_no_longer_published(): void
    {
        $visible = Product::factory()->published()->create(['name' => 'Still on sale']);
        $withdrawn = Product::factory()->draft()->create(['name' => 'Withdrawn']);

        HomepageSection::factory()
            ->ofType(SectionType::ProductCollection, [
                'product_ids' => [$visible->id, $withdrawn->id],
            ])
            ->create();

        $response = $this->getJson('/api/v1/homepage')->assertOk();

        // Content is resolved at read time, never snapshotted — which is why
        // unpublishing a product removes it from the homepage without anyone
        // re-saving the section.
        $this->assertSame(['Still on sale'], array_column($response->json('data.0.items'), 'name'));
    }

    #[Test]
    public function homepage_sections_never_expose_internal_id_lists(): void
    {
        $product = Product::factory()->published()->create();

        HomepageSection::factory()
            ->ofType(SectionType::ProductCollection, ['product_ids' => [$product->id]])
            ->create();

        $settings = $this->getJson('/api/v1/homepage')->assertOk()->json('data.0.settings');

        // Already resolved into `items`; echoing them would publish which
        // internal ids back a rail for no benefit.
        $this->assertArrayNotHasKey('product_ids', $settings);
    }

    #[Test]
    public function homepage_product_cards_never_expose_cost_price_or_exact_stock(): void
    {
        Product::factory()->published()->create([
            'is_featured' => true,
            'cost_price' => 1_200,
            'stock' => 7,
        ]);

        HomepageSection::factory()->ofType(SectionType::FeaturedProducts)->create();

        $body = $this->getJson('/api/v1/homepage')->assertOk()->getContent();

        // The homepage payload is cached and shared by every visitor, so its
        // product cards are resolved against a neutral request rather than the
        // real one — otherwise an admin previewing the page would write margin
        // data into the entry the public then reads.
        $this->assertStringNotContainsString('cost_price', (string) $body);
        $this->assertStringNotContainsString('"stock"', (string) $body);
    }

    #[Test]
    public function a_categories_section_falls_back_to_top_level_categories(): void
    {
        Category::factory()->published()->create(['name' => 'Outerwear']);
        Category::factory()->draft()->create(['name' => 'Unreleased']);

        HomepageSection::factory()->ofType(SectionType::Categories)->create();

        $names = array_column(
            $this->getJson('/api/v1/homepage')->assertOk()->json('data.0.items'),
            'name',
        );

        $this->assertContains('Outerwear', $names);
        $this->assertNotContains('Unreleased', $names);
    }

    /*
    |--------------------------------------------------------------------------
    | Banners
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function the_banner_endpoint_returns_only_live_banners(): void
    {
        Banner::factory()->create(['title' => 'Live']);
        Banner::factory()->draft()->create(['title' => 'Draft']);
        Banner::factory()->scheduled()->create(['title' => 'Upcoming']);
        Banner::factory()->expired()->create(['title' => 'Finished']);

        $titles = array_column($this->getJson('/api/v1/banners')->assertOk()->json('data'), 'title');

        $this->assertSame(['Live'], $titles);
    }

    #[Test]
    public function banners_can_be_filtered_by_placement(): void
    {
        Banner::factory()->placement(BannerPlacement::HeroSlider)->create(['title' => 'Hero']);
        Banner::factory()->placement(BannerPlacement::Sidebar)->create(['title' => 'Sidebar']);

        $titles = array_column(
            $this->getJson('/api/v1/banners?placement=sidebar')->assertOk()->json('data'),
            'title',
        );

        $this->assertSame(['Sidebar'], $titles);
    }

    #[Test]
    public function an_unknown_placement_is_rejected_rather_than_ignored(): void
    {
        // Silently returning everything would read as "the filter did nothing",
        // which is the hardest kind of bug to notice.
        $this->getJson('/api/v1/banners?placement=nowhere')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function the_public_banner_payload_omits_status_and_scheduling(): void
    {
        Banner::factory()->create();

        $banner = $this->getJson('/api/v1/banners')->assertOk()->json('data.0');

        // The API only ever returns live banners, so there is nothing for a
        // client to filter — and giving it these fields would invite it to try.
        $this->assertArrayNotHasKey('status', $banner);
        $this->assertArrayNotHasKey('starts_at', $banner);
        $this->assertArrayNotHasKey('ends_at', $banner);
    }

    /*
    |--------------------------------------------------------------------------
    | CMS pages
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_published_page_is_reachable_by_slug(): void
    {
        CmsPage::factory()->create([
            'slug' => 'refund-policy',
            'title' => 'Refund Policy',
            'content' => '<p>Thirty days.</p>',
        ]);

        $this->getJson('/api/v1/pages/refund-policy')
            ->assertOk()
            ->assertJsonPath('data.title', 'Refund Policy')
            ->assertJsonPath('data.content', '<p>Thirty days.</p>');
    }

    #[Test]
    public function a_draft_page_is_not_reachable_by_slug(): void
    {
        CmsPage::factory()->draft()->create(['slug' => 'unreleased-policy']);

        // Deliberately indistinguishable from a slug that never existed.
        $this->getJson('/api/v1/pages/unreleased-policy')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function a_page_outside_its_schedule_is_not_reachable(): void
    {
        CmsPage::factory()->scheduled()->create(['slug' => 'future-terms']);
        CmsPage::factory()->expired()->create(['slug' => 'lapsed-offer']);

        $this->getJson('/api/v1/pages/future-terms')->assertNotFound();
        $this->getJson('/api/v1/pages/lapsed-offer')->assertNotFound();
    }

    #[Test]
    public function the_page_index_omits_body_content(): void
    {
        CmsPage::factory()->create([
            'title' => 'About Us',
            'content' => str_repeat('<p>A very long policy document.</p>', 200),
        ]);

        $page = $this->getJson('/api/v1/pages')->assertOk()->json('data.0');

        // A footer needs titles and slugs; sending six full policy documents to
        // render six links would dominate the payload of every page on the site.
        $this->assertSame('About Us', $page['title']);
        $this->assertArrayNotHasKey('content', $page);
    }

    #[Test]
    public function a_page_exposes_its_seo_fields(): void
    {
        CmsPage::factory()->create([
            'slug' => 'shipping-policy',
            'title' => 'Shipping Policy',
            'seo_title' => 'Delivery information',
            'seo_description' => 'How and when we ship.',
            'is_indexable' => false,
        ]);

        $this->getJson('/api/v1/pages/shipping-policy')
            ->assertOk()
            ->assertJsonPath('data.seo.title', 'Delivery information')
            ->assertJsonPath('data.seo.description', 'How and when we ship.')
            ->assertJsonPath('data.seo.indexable', false);
    }

    #[Test]
    public function a_page_without_an_seo_title_falls_back_to_its_title(): void
    {
        CmsPage::factory()->create([
            'slug' => 'contact',
            'title' => 'Contact',
            'seo_title' => null,
        ]);

        // An empty <title> is worse than a derived one.
        $this->getJson('/api/v1/pages/contact')
            ->assertOk()
            ->assertJsonPath('data.seo.title', 'Contact');
    }
}
