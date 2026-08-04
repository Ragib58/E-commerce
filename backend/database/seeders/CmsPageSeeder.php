<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PublishStatus;
use App\Models\CmsPage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds the six pages a storefront needs to open for business.
 *
 * Idempotent, and safe on a live installation: a page is created only if its
 * slug is absent, so re-running never overwrites text an operator has since
 * rewritten. The `is_system` flag is refreshed on every run, because that is
 * the delete guard and it must not drift.
 *
 * The bodies below are placeholders, and say so. Seeding a plausible-looking
 * refund policy would be worse than seeding an obvious stub: an operator who
 * skims a finished-looking page is liable to publish terms nobody wrote, and a
 * store's legal text is not something a framework should invent.
 */
final class CmsPageSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->pages() as $index => $page) {
            /** @var CmsPage|null $existing */
            $existing = CmsPage::query()->where('slug', $page['slug'])->first();

            if ($existing !== null) {
                // Only the protection flag is refreshed. Title, body, and
                // status belong to the operator once the page exists.
                $existing->forceFill(['is_system' => true])->saveQuietly();

                continue;
            }

            $model = CmsPage::query()->create([
                'title' => $page['title'],
                'slug' => $page['slug'],
                'excerpt' => $page['excerpt'],
                'content' => $page['content'],
                'seo_title' => $page['title'],
                'seo_description' => $page['excerpt'],
                'is_indexable' => true,

                /*
                 * Seeded as drafts, deliberately.
                 *
                 * A placeholder privacy policy published on day one is a
                 * compliance liability — it is worse to show visitors an empty
                 * policy than no policy. The operator publishes each page once
                 * they have written it.
                 */
                'status' => PublishStatus::Draft->value,
                'sort_order' => $index,
                'published_at' => null,
            ]);

            $model->forceFill(['is_system' => true])->saveQuietly();
        }
    }

    /**
     * @return array<int, array{title: string, slug: string, excerpt: string, content: string}>
     */
    private function pages(): array
    {
        return [
            [
                'title' => 'About Us',
                'slug' => 'about-us',
                'excerpt' => 'Who we are and what we sell.',
                'content' => $this->placeholder(
                    'About Us',
                    'Tell customers who you are, what you sell, and why you started. '
                    . 'This is usually the second page a new visitor opens.',
                ),
            ],
            [
                'title' => 'Contact',
                'slug' => 'contact',
                'excerpt' => 'How to reach us.',
                'content' => $this->placeholder(
                    'Contact',
                    'Add the ways customers can reach you. Your phone number, email address, '
                    . 'and trading address are already stored in Settings and are shown in the '
                    . 'site footer automatically — use this page for anything beyond them, such '
                    . 'as opening hours or a contact form.',
                ),
            ],
            [
                'title' => 'Privacy Policy',
                'slug' => 'privacy-policy',
                'excerpt' => 'What personal data we collect and how we use it.',
                'content' => $this->placeholder(
                    'Privacy Policy',
                    'Describe what personal data you collect, why you collect it, how long you '
                    . 'keep it, who you share it with, and how a customer can request its '
                    . 'deletion. This page has legal force — have it reviewed rather than '
                    . 'adapting someone else’s.',
                ),
            ],
            [
                'title' => 'Terms & Conditions',
                'slug' => 'terms-and-conditions',
                'excerpt' => 'The terms of sale between you and us.',
                'content' => $this->placeholder(
                    'Terms & Conditions',
                    'Set out the contract between your store and your customers: when an order '
                    . 'is accepted, pricing and payment terms, liability, and the governing law. '
                    . 'This page has legal force — have it reviewed.',
                ),
            ],
            [
                'title' => 'Refund Policy',
                'slug' => 'refund-policy',
                'excerpt' => 'How returns and refunds work.',
                'content' => $this->placeholder(
                    'Refund Policy',
                    'Explain the return window, the condition goods must be in, who pays return '
                    . 'postage, and how long a refund takes to reach the customer. Statutory '
                    . 'return rights apply in most jurisdictions regardless of what this page says.',
                ),
            ],
            [
                'title' => 'Shipping Policy',
                'slug' => 'shipping-policy',
                'excerpt' => 'Delivery options, timescales, and costs.',
                'content' => $this->placeholder(
                    'Shipping Policy',
                    'List where you ship to, the carriers and services you offer, dispatch and '
                    . 'delivery timescales, and how shipping is charged.',
                ),
            ],
        ];
    }

    /**
     * A visibly unfinished body.
     *
     * The banner is the point: it makes an unwritten page impossible to publish
     * by accident, which a lorem-ipsum stub would not.
     */
    private function placeholder(string $title, string $guidance): string
    {
        return <<<HTML
        <p><strong>This page has not been written yet.</strong> Replace this text in
        the admin panel under Content &rarr; Pages, then set the page to Published.</p>
        <h2>{$title}</h2>
        <p>{$guidance}</p>
        HTML;
    }
}
