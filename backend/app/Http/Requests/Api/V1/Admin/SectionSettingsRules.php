<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\SectionType;
use Illuminate\Validation\Rule;

/**
 * Per-type validation for a homepage section's `settings` payload.
 *
 * Shared by the create and update requests so the two cannot disagree about
 * what a valid hero slider looks like — a divergence that would show up only
 * as "it saved the first time and was rejected on edit".
 *
 * Why the settings are validated at all, given the column is JSON: these values
 * reach SQL LIMITs, grid layouts, and — for custom content — the rendered page.
 * An unvalidated `limit` is a full catalog dump; an unvalidated `columns` is a
 * broken layout; an unvalidated id list is untyped input heading for a whereIn.
 * The model clamps the numeric ones defensively as well, but a rejected save
 * with an explanation beats a silently corrected one.
 */
trait SectionSettingsRules
{
    /**
     * Rules for the settings sub-object of one section type.
     *
     * Keyed `settings.*` so they compose with the outer request's rules. An
     * unknown or absent type yields no rules: the `type` field's own enum rule
     * is what reports that, and piling on a second error for every setting
     * would bury it.
     *
     * @return array<string, mixed>
     */
    protected function settingsRules(?SectionType $type): array
    {
        if ($type === null) {
            return [];
        }

        $maxItems = (int) config('content.homepage.max_items_per_section', 48);

        $common = [
            'settings.limit' => ['sometimes', 'integer', 'min:1', 'max:' . $maxItems],
            'settings.columns' => ['sometimes', 'integer', 'min:1', 'max:6'],
        ];

        return match ($type) {
            SectionType::HeroSlider => [
                'settings.autoplay' => ['sometimes', 'boolean'],
                // Floor of 2s: anything faster is unreadable, and for a
                // screen-reader user it is actively hostile.
                'settings.interval' => ['sometimes', 'integer', 'min:2000', 'max:30000'],
                'settings.show_arrows' => ['sometimes', 'boolean'],
                'settings.show_dots' => ['sometimes', 'boolean'],
                'settings.height' => ['sometimes', Rule::in(['small', 'medium', 'large', 'full'])],
                'settings.limit' => ['sometimes', 'integer', 'min:1', 'max:12'],
            ],

            SectionType::PromoBanner => [
                'settings.layout' => ['sometimes', Rule::in(['full', 'split', 'grid'])],
                'settings.aspect_ratio' => ['sometimes', Rule::in(['21:9', '16:9', '4:3', '3:1', '1:1'])],
                'settings.limit' => ['sometimes', 'integer', 'min:1', 'max:8'],
            ],

            SectionType::FeaturedProducts, SectionType::NewArrivals, SectionType::BestSellers => array_merge($common, [
                'settings.show_view_all' => ['sometimes', 'boolean'],
            ]),

            SectionType::Categories => array_merge($common, [
                'settings.category_ids' => ['sometimes', 'array', 'max:' . $maxItems],
                // `exists` rather than merely `integer`: an id that does not
                // resolve produces a silently short grid, and the operator has
                // no way to tell which pick vanished.
                'settings.category_ids.*' => ['integer', Rule::exists('categories', 'id')],
                'settings.show_product_count' => ['sometimes', 'boolean'],
            ]),

            SectionType::FlashSale => array_merge($common, [
                'settings.product_ids' => ['sometimes', 'array', 'max:' . $maxItems],
                'settings.product_ids.*' => ['integer', Rule::exists('products', 'id')],
                'settings.show_countdown' => ['sometimes', 'boolean'],
            ]),

            SectionType::ProductCollection => array_merge($common, [
                'settings.product_ids' => ['sometimes', 'array', 'max:' . $maxItems],
                'settings.product_ids.*' => ['integer', Rule::exists('products', 'id')],
                'settings.category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')],
            ]),

            SectionType::Testimonials => [
                'settings.columns' => ['sometimes', 'integer', 'min:1', 'max:4'],
                'settings.items' => ['sometimes', 'array', 'max:24'],
                'settings.items.*.quote' => ['required', 'string', 'max:600'],
                'settings.items.*.author' => ['nullable', 'string', 'max:120'],
                'settings.items.*.role' => ['nullable', 'string', 'max:120'],
                'settings.items.*.avatar' => ['nullable', 'string', 'max:512'],
                'settings.items.*.rating' => ['nullable', 'integer', 'min:0', 'max:5'],
            ],

            SectionType::BlogPosts => array_merge($common, [
                'settings.limit' => ['sometimes', 'integer', 'min:1', 'max:12'],
            ]),

            SectionType::CustomContent => [
                'settings.content' => ['sometimes', 'nullable', 'string', 'max:' . (int) config('content.html.max_length', 200000)],
                'settings.image' => ['sometimes', 'nullable', 'string', 'max:512'],
                'settings.image_position' => ['sometimes', Rule::in(['left', 'right', 'top', 'bottom', 'background'])],
                'settings.cta_label' => ['sometimes', 'nullable', 'string', 'max:80'],
                // Same constraint as a banner link: `url` alone would admit
                // `javascript:`, which is a stored XSS payload in an href.
                'settings.cta_url' => ['sometimes', 'nullable', 'string', 'max:512', 'regex:/^(https?:\/\/|\/)[^\s<>"]*$/i'],
            ],
        };
    }

    /**
     * @return array<string, string>
     */
    protected function settingsMessages(): array
    {
        return [
            'settings.interval.min' => 'A slide must stay on screen for at least two seconds.',
            'settings.product_ids.*.exists' => 'One of the selected products no longer exists.',
            'settings.category_ids.*.exists' => 'One of the selected categories no longer exists.',
            'settings.items.*.quote.required' => 'Every testimonial needs a quote.',
            'settings.cta_url.regex' => 'The button link must be a full http(s) address or a path beginning with “/”.',
        ];
    }
}
