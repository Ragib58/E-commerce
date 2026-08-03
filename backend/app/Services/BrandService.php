<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductStatus;
use App\Events\CatalogChanged;
use App\Models\Brand;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Brand lifecycle.
 *
 * Simpler than categories — brands are flat — so the only rule that cannot live
 * in the schema is the one about deleting a brand that still has products.
 */
final class BrandService
{
    public function __construct(
        private readonly MediaService $media,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Brand
    {
        $brand = DB::transaction(function () use ($data): Brand {
            $brand = Brand::query()->create([
                'name' => $data['name'],
                'slug' => Brand::generateSlug(
                    ! empty($data['slug']) ? (string) $data['slug'] : (string) $data['name'],
                ),
                'description' => $data['description'] ?? null,
                'meta_title' => $data['meta_title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
                'status' => $data['status'] ?? ProductStatus::Draft->value,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);

            $this->storeLogo($brand, $data);

            return $brand;
        });

        CatalogChanged::dispatch('brand', $brand->slug, $brand->status->isVisible());

        return $brand->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Brand $brand, array $data): Brand
    {
        $wasPublic = $brand->status->isVisible();

        $updated = DB::transaction(function () use ($brand, $data): Brand {
            $brand->fill(array_filter([
                'name' => $data['name'] ?? null,
                'description' => $data['description'] ?? null,
                'meta_title' => $data['meta_title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
                'status' => $data['status'] ?? null,
                'sort_order' => $data['sort_order'] ?? null,
            ], static fn (mixed $value): bool => $value !== null));

            // Only on explicit request: an automatic reslug on rename breaks
            // every existing link to the brand page.
            if (! empty($data['slug'])) {
                $brand->slug = Brand::generateSlug((string) $data['slug'], (int) $brand->getKey());
            }

            $brand->save();

            $this->storeLogo($brand, $data);

            return $brand;
        });

        CatalogChanged::dispatch('brand', $updated->slug, $updated->status->isVisible(), $wasPublic);

        return $updated->refresh();
    }

    /**
     * @throws ValidationException
     */
    public function delete(Brand $brand, bool $cascade = false): void
    {
        if (! $cascade && $brand->products()->exists()) {
            throw ValidationException::withMessages([
                'brand' => ['This brand still has products assigned. Reassign them first, or confirm the cascade.'],
            ]);
        }

        $slug = $brand->slug;
        $wasPublic = $brand->status->isVisible();

        DB::transaction(function () use ($brand): void {
            // Products outlive their brand: they are saleable inventory with
            // order history, and tidying a brand list must not delete them.
            $brand->products()->update(['brand_id' => null]);

            $this->media->delete($brand->logo);

            $brand->delete();
        });

        CatalogChanged::dispatch('brand', $slug, false, $wasPublic);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeLogo(Brand $brand, array $data): void
    {
        $logo = $data['logo'] ?? null;

        if ($logo instanceof UploadedFile) {
            $brand->logo = $this->media->replace($logo, $brand->logo, 'branding');
            $brand->save();

            return;
        }

        // Explicit null clears; an absent key leaves the logo untouched.
        if (array_key_exists('logo', $data) && $data['logo'] === null && $brand->logo !== null) {
            $this->media->delete($brand->logo);
            $brand->logo = null;
            $brand->save();
        }
    }
}
