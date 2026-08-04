<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BannerPlacement;
use App\Enums\PublishStatus;
use App\Events\ContentChanged;
use App\Models\Banner;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Banner lifecycle and placement queries.
 *
 * The read path is placement-oriented: the storefront asks "what belongs in the
 * hero right now?", never "give me banner 12". That indirection is what lets an
 * operator swap a campaign without a deploy.
 */
final class BannerService
{
    public function __construct(
        private readonly MediaService $media,
    ) {
    }

    /**
     * Live banners for one placement, in display order.
     *
     * @return EloquentCollection<int, Banner>
     */
    public function liveForPlacement(BannerPlacement|string $placement, ?int $limit = null): EloquentCollection
    {
        return Banner::query()
            ->live()
            ->placement($placement)
            ->ordered()
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get();
    }

    /**
     * Live banners across several placements in a single query.
     *
     * The homepage typically needs both a hero and a promo strip; issuing one
     * query per placement would make the number of round trips a function of
     * how the page happens to be configured.
     *
     * @param  array<int, BannerPlacement|string>  $placements
     * @return Collection<int, Banner>
     */
    public function liveForPlacements(array $placements): Collection
    {
        if ($placements === []) {
            return collect();
        }

        $values = array_map(
            static fn (BannerPlacement|string $placement): string => $placement instanceof BannerPlacement
                ? $placement->value
                : $placement,
            $placements,
        );

        return Banner::query()
            ->live()
            ->whereIn('placement', $values)
            ->ordered()
            ->get();
    }

    /**
     * Every banner, filtered, for the admin list.
     *
     * @param  array<string, mixed>  $filters
     * @return EloquentCollection<int, Banner>
     */
    public function all(array $filters = []): EloquentCollection
    {
        return Banner::query()
            ->when(
                ! empty($filters['placement']),
                fn ($query) => $query->placement((string) $filters['placement']),
            )
            ->when(
                ! empty($filters['status']),
                fn ($query) => $query->where('status', (string) $filters['status']),
            )
            ->orderBy('placement')
            ->ordered()
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Banner
    {
        $banner = DB::transaction(function () use ($data): Banner {
            /*
             * The image is stored before the row is inserted.
             *
             * The reverse order would need a placeholder in a NOT NULL column
             * and a second write to correct it — and if that write failed, the
             * table would hold a banner pointing at nothing. Storing first
             * means a failed upload aborts before any row exists; the
             * transaction then rolls back around a file that the next
             * unreferenced-media sweep reclaims.
             */
            $image = $data['image'] instanceof UploadedFile
                ? $this->media->store($data['image'], 'banners')
                : (string) $data['image'];

            $mobileImage = ($data['mobile_image'] ?? null) instanceof UploadedFile
                ? $this->media->store($data['mobile_image'], 'banners')
                : null;

            $banner = Banner::query()->create([
                'title' => $data['title'],
                'subtitle' => $data['subtitle'] ?? null,
                'image' => $image,
                'mobile_image' => $mobileImage,
                'alt_text' => $data['alt_text'] ?? null,
                'link_url' => $data['link_url'] ?? null,
                'link_label' => $data['link_label'] ?? null,
                'link_external' => $data['link_external'] ?? false,
                'placement' => $data['placement'],
                'status' => $data['status'] ?? PublishStatus::Draft->value,
                'sort_order' => $data['sort_order'] ?? $this->nextSortOrder($data['placement']),
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
            ]);

            return $banner;
        });

        ContentChanged::dispatch('banner', (string) $banner->id, $this->isLive($banner));

        return $banner->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Banner $banner, array $data): Banner
    {
        $wasLive = $this->isLive($banner);

        DB::transaction(function () use ($banner, $data): void {
            foreach (['title', 'subtitle', 'alt_text', 'link_url', 'link_label', 'placement', 'status'] as $field) {
                if (array_key_exists($field, $data)) {
                    $banner->{$field} = $data[$field];
                }
            }

            if (array_key_exists('link_external', $data)) {
                $banner->link_external = (bool) $data['link_external'];
            }

            if (array_key_exists('sort_order', $data)) {
                $banner->sort_order = (int) $data['sort_order'];
            }

            // Nullable and clearable: an operator removing an end date makes
            // the campaign open-ended, which a truthiness check would prevent.
            foreach (['starts_at', 'ends_at'] as $field) {
                if (array_key_exists($field, $data)) {
                    $banner->{$field} = $data[$field];
                }
            }

            $banner->save();

            $this->storeImages($banner, $data);
        });

        ContentChanged::dispatch('banner', (string) $banner->id, $this->isLive($banner->refresh()), $wasLive);

        return $banner;
    }

    public function delete(Banner $banner): void
    {
        $wasLive = $this->isLive($banner);

        DB::transaction(function () use ($banner): void {
            $this->media->delete($banner->image);
            $this->media->delete($banner->mobile_image);

            $banner->delete();
        });

        ContentChanged::dispatch('banner', (string) $banner->id, false, $wasLive);
    }

    /**
     * Persist a drag-and-drop rearrangement in one transaction.
     *
     * @param  array<int, array{id: int, sort_order: int}>  $items
     */
    public function reorder(array $items): void
    {
        DB::transaction(function () use ($items): void {
            foreach ($items as $item) {
                Banner::query()
                    ->whereKey($item['id'])
                    ->update(['sort_order' => (int) $item['sort_order']]);
            }
        });

        ContentChanged::dispatch('banner');
    }

    /**
     * Store or replace a banner's images.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeImages(Banner $banner, array $data): void
    {
        $changed = false;

        foreach (['image', 'mobile_image'] as $field) {
            $file = $data[$field] ?? null;

            if ($file instanceof UploadedFile) {
                $banner->{$field} = $this->media->replace($file, $banner->{$field} ?: null, 'banners');
                $changed = true;

                continue;
            }

            // An explicit null clears the asset; an absent key leaves it alone.
            // The primary image is exempt: a banner without one has nothing to
            // render, so clearing it is refused at the request layer.
            if ($field !== 'image'
                && array_key_exists($field, $data)
                && $data[$field] === null
                && $banner->{$field} !== null) {
                $this->media->delete($banner->{$field});
                $banner->{$field} = null;
                $changed = true;
            }
        }

        if ($changed) {
            $banner->save();
        }
    }

    /**
     * Whether a banner is visible to shoppers as it currently stands.
     */
    private function isLive(Banner $banner): bool
    {
        return $banner->status->isPublishable() && $banner->isWithinWindow();
    }

    private function nextSortOrder(BannerPlacement|string $placement): int
    {
        return (int) Banner::query()->placement($placement)->max('sort_order') + 1;
    }
}
