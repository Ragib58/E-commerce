<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BannerPlacement;
use App\Enums\SectionType;
use App\Events\ContentChanged;
use App\Models\Banner;
use App\Models\Category;
use App\Models\HomepageSection;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assembles the homepage and manages its sections.
 *
 * The read path answers one question — "what should the storefront render right
 * now?" — and answers it completely: each section arrives with its content
 * already resolved, so the frontend performs one request for the whole page
 * rather than one per rail.
 *
 * Three rules shape the implementation:
 *
 *   - **Content is resolved at read time, never snapshotted.** A featured rail
 *     stores `{"limit": 8}`, not eight product ids. Snapshotting would leave an
 *     unpublished product advertised on the homepage until someone re-saved an
 *     unrelated section.
 *   - **Resolution is batched by type.** Sections are grouped so that three
 *     product rails cost one query per distinct selection, not one per section
 *     per product. A homepage is the most-requested page on the site and is the
 *     wrong place for an N+1.
 *   - **The cache TTL respects the schedule.** A flash sale ending in two
 *     minutes must not be cached for ten. See {@see resolveTtl()}.
 */
final class HomepageService
{
    /**
     * Memoised neutral request for resolving cached product cards.
     * See {@see storefrontRequest()} for why it must not be the real one.
     */
    private ?\Illuminate\Http\Request $neutralRequest = null;

    public function __construct(
        private readonly BannerService $banners,
        private readonly HtmlSanitiser $sanitiser,
    ) {
    }

    /**
     * The rendered homepage: live sections, in order, with content resolved.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function render(?Carbon $at = null): Collection
    {
        $at ??= Carbon::now();

        $sections = $this->liveSections($at);

        if ($sections->isEmpty()) {
            return collect();
        }

        // Resolved once for the whole page and handed to each section, so two
        // product rails share one round of catalog queries.
        $context = $this->buildContext($sections);

        return $sections
            ->map(fn (HomepageSection $section): array => $this->renderSection($section, $context))
            // A section whose content resolved to nothing is dropped rather
            // than emitted empty: a "Best sellers" heading above a blank strip
            // reads as a broken page, and the frontend cannot tell the
            // difference between "not configured" and "nothing matched".
            ->filter(static fn (array $payload): bool => $payload['has_content'])
            ->values();
    }

    /**
     * The cached homepage payload.
     *
     * Cached because it is identical for every visitor and changes only when an
     * admin saves — but only for as long as the schedule permits.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function cachedRender(): Collection
    {
        if (! $this->cacheEnabled()) {
            return $this->render();
        }

        $now = Carbon::now();

        return Cache::tags([$this->cacheTag()])->remember(
            'content:homepage',
            $this->resolveTtl($now),
            fn (): Collection => $this->render($now),
        );
    }

    /**
     * Live sections, ordered, straight from the database.
     *
     * @return EloquentCollection<int, HomepageSection>
     */
    private function liveSections(Carbon $at): EloquentCollection
    {
        return HomepageSection::query()
            ->live($at)
            ->ordered()
            ->limit((int) config('content.homepage.max_sections', 40))
            ->get();
    }

    /**
     * How long the homepage may be cached.
     *
     * The configured TTL, shortened to the next scheduled transition. Without
     * this, a section due to appear in one minute would not show up for ten,
     * and — worse — an expired flash sale would keep its countdown on screen
     * after it ended. Scheduling that only takes effect on the next cache miss
     * is not scheduling.
     */
    private function resolveTtl(Carbon $at): int
    {
        $ttl = (int) config('content.cache.ttl', 600);

        /*
         * The next boundary across every section and banner, including ones
         * not currently live — a section starting in 30 seconds is invisible to
         * `live()` yet is exactly the transition the TTL must not outlast.
         */
        $next = collect([
            HomepageSection::query()->where('starts_at', '>', $at)->min('starts_at'),
            HomepageSection::query()->where('ends_at', '>', $at)->min('ends_at'),
            Banner::query()->where('starts_at', '>', $at)->min('starts_at'),
            Banner::query()->where('ends_at', '>', $at)->min('ends_at'),
        ])
            ->filter()
            ->map(static fn (mixed $timestamp): Carbon => Carbon::parse((string) $timestamp))
            ->min();

        if ($next === null) {
            return $ttl;
        }

        $secondsUntil = $at->diffInSeconds($next, absolute: false);

        // At least 10s: a transition seconds away would otherwise produce a
        // zero or negative TTL, which most cache drivers read as "forever".
        return (int) max(10, min($ttl, $secondsUntil));
    }

    /**
     * Pre-resolve every catalog read the page needs, batched by concern.
     *
     * @param  EloquentCollection<int, HomepageSection>  $sections
     * @return array<string, mixed>
     */
    private function buildContext(EloquentCollection $sections): array
    {
        $types = $sections->map(static fn (HomepageSection $s): SectionType => $s->type);

        $context = [
            'products' => new EloquentCollection(),
            'categories' => new EloquentCollection(),
            'banners' => collect(),
        ];

        /*
         * Every product id any section names explicitly, fetched in one query.
         *
         * Hand-picked collections and flash sales both reference ids; without
         * this batch, three collections of eight products would be three
         * queries, and a naive implementation would make it twenty-four.
         */
        $explicitIds = $sections
            ->flatMap(static fn (HomepageSection $section): array => array_merge(
                $section->idListSetting('product_ids'),
                [],
            ))
            ->unique()
            ->values()
            ->all();

        if ($explicitIds !== []) {
            $context['products'] = Product::query()
                ->published()
                ->withListingRelations()
                ->whereIn('id', $explicitIds)
                ->get()
                ->keyBy('id');
        }

        $categoryIds = $sections
            ->flatMap(static fn (HomepageSection $section): array => $section->idListSetting('category_ids'))
            ->unique()
            ->values()
            ->all();

        if ($categoryIds !== []) {
            $context['categories'] = Category::query()
                ->published()
                ->withCount('products')
                ->whereIn('id', $categoryIds)
                ->get()
                ->keyBy('id');
        }

        /*
         * Banners for every placement the page uses, in one query rather than
         * one per slider.
         */
        $placements = $types
            ->map(static fn (SectionType $type): ?BannerPlacement => match ($type) {
                SectionType::HeroSlider => BannerPlacement::HeroSlider,
                SectionType::PromoBanner => BannerPlacement::HomepagePromo,
                default => null,
            })
            ->filter()
            ->unique()
            ->values();

        if ($placements->isNotEmpty()) {
            $context['banners'] = $this->banners->liveForPlacements($placements->all());
        }

        return $context;
    }

    /**
     * Turn one section row into its rendered payload.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function renderSection(HomepageSection $section, array $context): array
    {
        $items = $this->resolveItems($section, $context);

        return [
            'id' => $section->id,
            'type' => $section->type->value,
            'name' => $section->name,
            'heading' => $section->heading,
            'subheading' => $section->subheading,
            'settings' => $this->publicSettings($section),
            'style' => [
                'background_color' => $section->background_color,
                'container_width' => $section->container_width ?? 'default',
            ],
            'sort_order' => $section->sort_order,
            'starts_at' => $section->starts_at?->toIso8601String(),
            'ends_at' => $section->ends_at?->toIso8601String(),

            // The resolved content, under a key whose meaning depends on type.
            'items' => $items,

            /*
             * Whether this section has anything to show.
             *
             * Computed here rather than left to the frontend because only the
             * backend knows which types are content-bearing: a custom-content
             * block with body text has no `items` at all and is still perfectly
             * renderable.
             */
            'has_content' => $items !== [] || $this->isSelfContained($section),
        ];
    }

    /**
     * Resolve a section's content according to its type.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, mixed>
     */
    private function resolveItems(HomepageSection $section, array $context): array
    {
        $maxItems = (int) config('content.homepage.max_items_per_section', 48);
        $limit = $section->intSetting('limit', 8, 1, $maxItems);

        return match ($section->type) {
            SectionType::HeroSlider => $this->resolveBanners($context, BannerPlacement::HeroSlider, $limit),
            SectionType::PromoBanner => $this->resolveBanners($context, BannerPlacement::HomepagePromo, $limit),

            SectionType::FeaturedProducts => $this->resolveRail('featured', $limit),
            SectionType::NewArrivals => $this->resolveRail('new_arrivals', $limit),
            SectionType::BestSellers => $this->resolveRail('best_sellers', $limit),

            SectionType::Categories => $this->resolveCategories($section, $context, $limit),
            SectionType::FlashSale, SectionType::ProductCollection => $this->resolveCollection($section, $context, $limit),

            SectionType::Testimonials => $this->resolveTestimonials($section, $limit),

            // Blog posts are a later phase. The section type exists, is
            // configurable, and resolves empty — so an operator can place and
            // schedule it now, and it begins rendering when the module lands
            // without a frontend change.
            SectionType::BlogPosts => [],

            SectionType::CustomContent => [],
        };
    }

    /**
     * Whether a section renders meaningfully with no resolved items.
     *
     * A custom-content block is its own content, and a blog section is a
     * deliberate placeholder for a module that has not shipped.
     */
    private function isSelfContained(HomepageSection $section): bool
    {
        if ($section->type === SectionType::CustomContent) {
            return trim((string) $section->setting('content', '')) !== '';
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function resolveBanners(array $context, BannerPlacement $placement, int $limit): array
    {
        /** @var Collection<int, Banner> $banners */
        $banners = $context['banners'] ?? collect();

        return $banners
            ->filter(static fn (Banner $banner): bool => $banner->placement === $placement)
            ->take($limit)
            ->map(static fn (Banner $banner): array => [
                'id' => $banner->id,
                'title' => $banner->title,
                'subtitle' => $banner->subtitle,
                'image' => $banner->image_url,
                'mobile_image' => $banner->mobile_image_url,
                'alt_text' => $banner->resolved_alt_text,
                'link_url' => $banner->link_url,
                'link_label' => $banner->link_label,
                'link_external' => $banner->link_external,
            ])
            ->values()
            ->all();
    }

    /**
     * A catalog rail, delegated to CatalogService so its cache is shared.
     *
     * Resolved lazily rather than in buildContext: only the sections actually
     * present trigger a rail query, and CatalogService already caches each one
     * under the catalog tag — so two homepages asking for "featured" hit the
     * same entry.
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolveRail(string $rail, int $limit): array
    {
        return app(CatalogService::class)
            ->rail($rail, $limit)
            ->map(fn (Product $product): array => $this->productPayload($product))
            ->all();
    }

    /**
     * A hand-picked product list, in the operator's chosen order.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function resolveCollection(HomepageSection $section, array $context, int $limit): array
    {
        $ids = $section->idListSetting('product_ids', $limit);

        /** @var EloquentCollection<int, Product> $fetched */
        $fetched = $context['products'] ?? new EloquentCollection();

        if ($ids !== []) {
            /*
             * Ordered by the id list, not by the query result.
             *
             * whereIn returns rows in whatever order the index yields, which
             * would silently discard the merchandising sequence an operator
             * dragged into place. Missing ids (unpublished since being picked)
             * simply drop out.
             */
            return collect($ids)
                ->map(static fn (int $id): ?Product => $fetched->get($id))
                ->filter()
                ->take($limit)
                ->map(fn (Product $product): array => $this->productPayload($product))
                ->values()
                ->all();
        }

        // No explicit picks: fall back to a category, which is how a "Summer
        // sale" section stays populated as stock rotates.
        $categoryId = $section->setting('category_id');

        if ($categoryId === null) {
            return [];
        }

        $category = Category::query()->published()->find((int) $categoryId);

        if ($category === null) {
            return [];
        }

        return Product::query()
            ->published()
            ->withListingRelations()
            ->inCategory($category)
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get()
            ->map(fn (Product $product): array => $this->productPayload($product))
            ->all();
    }

    /**
     * Categories for a category grid.
     *
     * Explicit picks when configured, otherwise the top-level tree — so the
     * section is useful before anyone opens its settings.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function resolveCategories(HomepageSection $section, array $context, int $limit): array
    {
        $ids = $section->idListSetting('category_ids', $limit);

        /** @var EloquentCollection<int, Category> $fetched */
        $fetched = $context['categories'] ?? new EloquentCollection();

        $categories = $ids !== []
            ? collect($ids)->map(static fn (int $id): ?Category => $fetched->get($id))->filter()
            : Category::query()
                ->published()
                ->roots()
                ->withCount('products')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->limit($limit)
                ->get();

        return $categories
            ->take($limit)
            ->map(static fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'image' => $category->image_url,
                'description' => $category->description,
                'products_count' => $category->products_count ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * Testimonials, stored inline in the section's settings.
     *
     * Unlike products these have no catalog behind them and no reuse across
     * sections, so a table would add a join and a management screen for data
     * that is only ever edited alongside the section itself.
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolveTestimonials(HomepageSection $section, int $limit): array
    {
        $items = $section->setting('items', []);

        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->filter(static fn (mixed $item): bool => is_array($item) && ! empty($item['quote']))
            ->take($limit)
            ->map(fn (array $item): array => [
                // Plain text, not sanitised HTML: a testimonial is a quote, and
                // permitting markup here would be an injection surface with no
                // editorial benefit.
                'quote' => $this->sanitiser->toPlainText((string) $item['quote'], 600),
                'author' => isset($item['author']) ? $this->sanitiser->toPlainText((string) $item['author'], 120) : null,
                'role' => isset($item['role']) ? $this->sanitiser->toPlainText((string) $item['role'], 120) : null,
                'avatar' => $item['avatar'] ?? null,
                'rating' => isset($item['rating']) ? max(0, min(5, (int) $item['rating'])) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * The storefront-facing shape of a product card.
     *
     * Delegates to ProductResource rather than assembling a bespoke shape.
     * A second product payload would drift from the canonical one — the
     * frontend already parses `Product` from every other endpoint, and a
     * homepage-only variant would need its own schema, its own card component,
     * and its own bug when pricing rules change.
     *
     * Cards are cheap despite the full resource: `withListingRelations()` loads
     * only the thumbnail row and no variants, and the resource omits
     * `description` outside a show route, so the heavy fields are never
     * populated here.
     *
     * @return array<string, mixed>
     */
    private function productPayload(Product $product): array
    {
        return (new \App\Http\Resources\Api\V1\ProductResource($product))
            ->resolve($this->storefrontRequest());
    }

    /**
     * A neutral request for resolving cached product cards.
     *
     * ProductResource varies by request: it emits `cost_price` and exact stock
     * when an admin guard resolves, and the full description on a `*.show`
     * route. The homepage payload is cached and shared by every visitor, so
     * resolving it against the *actual* request would let one admin previewing
     * the page write margin data into the entry the public then reads.
     *
     * A bare Request satisfies neither condition, so the cached cards are
     * always the public shape regardless of who warmed the cache.
     *
     * Built once per instance: this runs for every card on the page, and the
     * homepage is the most-requested page on the site.
     */
    private function storefrontRequest(): \Illuminate\Http\Request
    {
        return $this->neutralRequest ??= new \Illuminate\Http\Request();
    }

    /**
     * Section settings, filtered to what the storefront needs.
     *
     * Id lists are stripped: they have already been resolved into `items`, and
     * echoing them would publish which internal ids back a rail for no benefit.
     * Custom content is sanitised on write, but is re-sanitised here as
     * defence in depth for rows written before the sanitiser existed or by a
     * direct database edit.
     *
     * @return array<string, mixed>
     */
    private function publicSettings(HomepageSection $section): array
    {
        $settings = $section->resolvedSettings();

        unset($settings['product_ids'], $settings['category_ids'], $settings['items']);

        if ($section->type === SectionType::CustomContent && isset($settings['content'])) {
            $settings['content'] = $this->sanitiser->sanitise((string) $settings['content']);
        }

        return $settings;
    }

    /*
    |--------------------------------------------------------------------------
    | Write paths
    |--------------------------------------------------------------------------
    */

    /**
     * Every section, live or not, for the admin builder.
     *
     * @return EloquentCollection<int, HomepageSection>
     */
    public function all(): EloquentCollection
    {
        return HomepageSection::query()->ordered()->get();
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): HomepageSection
    {
        $type = $data['type'] instanceof SectionType
            ? $data['type']
            : SectionType::from((string) $data['type']);

        $this->assertMultipleAllowed($type);

        $section = HomepageSection::query()->create([
            'type' => $type,
            'name' => $data['name'] ?? $type->label(),
            'heading' => $data['heading'] ?? null,
            'subheading' => $data['subheading'] ?? null,
            // Merged over the defaults so a section created with a partial
            // settings object still has every key its renderer reads.
            'settings' => $this->prepareSettings($type, $data['settings'] ?? []),
            'background_color' => $data['background_color'] ?? null,
            'container_width' => $data['container_width'] ?? null,
            'is_enabled' => $data['is_enabled'] ?? true,
            'sort_order' => $data['sort_order'] ?? $this->nextSortOrder(),
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
        ]);

        ContentChanged::dispatch('homepage', (string) $section->id, $section->is_enabled);

        return $section;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(HomepageSection $section, array $data): HomepageSection
    {
        $wasLive = $section->is_enabled && $section->isWithinWindow();

        foreach (['name', 'heading', 'subheading', 'background_color', 'container_width'] as $field) {
            if (array_key_exists($field, $data)) {
                $section->{$field} = $data[$field];
            }
        }

        if (array_key_exists('settings', $data)) {
            /*
             * Merged over what is stored, not replaced.
             *
             * The builder saves one panel at a time — a scheduling form that
             * omitted `settings.items` would otherwise wipe every testimonial
             * on the section.
             */
            $section->settings = $this->prepareSettings(
                $section->type,
                array_merge($section->settings ?? [], (array) $data['settings']),
            );
        }

        if (array_key_exists('is_enabled', $data)) {
            $section->is_enabled = (bool) $data['is_enabled'];
        }

        if (array_key_exists('sort_order', $data)) {
            $section->sort_order = (int) $data['sort_order'];
        }

        // Scheduling bounds are nullable *and* clearable, so array_key_exists
        // is the test rather than a truthiness check — an operator removing an
        // end date must be able to make a campaign open-ended again.
        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $section->{$field} = $data[$field];
            }
        }

        $section->save();

        ContentChanged::dispatch(
            'homepage',
            (string) $section->id,
            $section->is_enabled && $section->isWithinWindow(),
            $wasLive,
        );

        return $section->refresh();
    }

    public function delete(HomepageSection $section): void
    {
        $wasLive = $section->is_enabled && $section->isWithinWindow();

        $section->delete();

        ContentChanged::dispatch('homepage', (string) $section->id, false, $wasLive);
    }

    /**
     * Persist a drag-and-drop rearrangement in one transaction.
     *
     * All or nothing: a partially applied reorder leaves the page in an order
     * nobody chose, which is worse than the previous order.
     *
     * @param  array<int, array{id: int, sort_order: int}>  $items
     */
    public function reorder(array $items): void
    {
        DB::transaction(function () use ($items): void {
            foreach ($items as $item) {
                HomepageSection::query()
                    ->whereKey($item['id'])
                    ->update(['sort_order' => (int) $item['sort_order']]);
            }
        });

        ContentChanged::dispatch('homepage');
    }

    /**
     * Toggle a section without touching anything else.
     */
    public function setEnabled(HomepageSection $section, bool $enabled): HomepageSection
    {
        return $this->update($section, ['is_enabled' => $enabled]);
    }

    /**
     * Merge submitted settings over the type's defaults.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function prepareSettings(SectionType $type, array $settings): array
    {
        $merged = array_merge($type->defaultSettings(), $settings);

        // Rich text is sanitised on write, so the stored value is the safe
        // value and no read path can bypass the filter.
        if ($type === SectionType::CustomContent && isset($merged['content'])) {
            $merged['content'] = $this->sanitiser->sanitise((string) $merged['content']);
        }

        return $merged;
    }

    /**
     * @throws ValidationException
     */
    private function assertMultipleAllowed(SectionType $type): void
    {
        if ($type->allowsMultiple()) {
            return;
        }

        if (HomepageSection::query()->where('type', $type->value)->exists()) {
            throw ValidationException::withMessages([
                'type' => [sprintf(
                    'A “%s” section already exists. Edit the existing one instead of adding a second.',
                    $type->label(),
                )],
            ]);
        }
    }

    private function nextSortOrder(): int
    {
        return (int) HomepageSection::query()->max('sort_order') + 1;
    }

    private function cacheEnabled(): bool
    {
        return (bool) config('content.cache.enabled', true)
            && Cache::getStore() instanceof \Illuminate\Cache\TaggableStore;
    }

    private function cacheTag(): string
    {
        return (string) config('content.cache.tag', 'content');
    }
}
