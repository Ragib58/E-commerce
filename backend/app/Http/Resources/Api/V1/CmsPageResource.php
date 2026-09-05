<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\CmsPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A CMS page.
 *
 * `content` is omitted from index responses. A footer needs six titles and
 * slugs; sending six full policy documents to render six links would dominate
 * the payload of every page on the site.
 *
 * @mixin CmsPage
 */
final class CmsPageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user('admin-api') !== null || $request->user('admin') !== null;

        /*
         * Whether the query actually selected a column.
         *
         * The index query uses `select()` to fetch titles and slugs only, so
         * the body and SEO fields are not merely null — they were never
         * loaded, and emitting them as null would tell a client the page is
         * empty when it is not.
         *
         * `array_key_exists` on the raw attributes, deliberately, rather than
         * the model's `offsetExists`: that helper returns false for a *null*
         * attribute as well as a missing one, which would drop the SEO block
         * from a legitimately empty draft.
         */
        $loaded = fn (string $column): bool => array_key_exists(
            $column,
            $this->resource->getAttributes(),
        );

        $includesContent = $loaded('content');

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,

            'content' => $this->when($includesContent, fn (): ?string => $this->content),

            'featured_image' => $this->when(
                $loaded('featured_image'),
                fn (): ?string => $this->featured_image_url,
            ),

            'seo' => $this->when($includesContent, fn (): array => [
                // Falls back to the page title so a page always has a usable
                // <title>, rather than the frontend inventing one.
                'title' => $this->seo_title ?: $this->title,
                'description' => $this->seo_description,
                'keywords' => $this->seo_keywords,
                'og_image' => $this->og_image_url,
                'indexable' => $this->is_indexable,
            ]),

            'sort_order' => $this->sort_order,
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            $this->mergeWhen($isAdmin, [
                'status' => $this->when($loaded('status'), fn (): string => $this->status->value),
                'is_system' => $this->when($loaded('is_system'), fn (): bool => $this->is_system),
                'starts_at' => $this->when(
                    $loaded('starts_at'),
                    fn (): ?string => $this->starts_at?->toIso8601String(),
                ),
                'ends_at' => $this->when(
                    $loaded('ends_at'),
                    fn (): ?string => $this->ends_at?->toIso8601String(),
                ),
                'window_state' => $this->when($loaded('starts_at'), fn (): string => $this->windowState()),

                /*
                 * Guarded like every other optional column above.
                 *
                 * The footer index selects a narrow column list on purpose —
                 * it must not ship six full policy documents to render six
                 * links — and `created_at` is not in it. Reading it
                 * unconditionally threw a MissingAttributeException under
                 * `Model::shouldBeStrict()`, turning the whole page index into
                 * a 500.
                 */
                'created_at' => $this->when(
                    $loaded('created_at'),
                    fn (): ?string => $this->created_at?->toIso8601String(),
                ),
            ]),
        ];
    }
}
