<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PublishStatus;
use App\Models\CmsPage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<CmsPage>
 */
final class CmsPageFactory extends Factory
{
    protected $model = CmsPage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::title($this->faker->unique()->words(3, true));

        return [
            'title' => $title,
            'slug' => Str::slug($title) . '-' . Str::lower(Str::random(4)),
            'excerpt' => $this->faker->sentence(12),
            'content' => '<p>' . $this->faker->paragraph() . '</p>',
            'featured_image' => null,
            'seo_title' => $title,
            'seo_description' => $this->faker->sentence(14),
            'seo_keywords' => null,
            'og_image' => null,
            'is_indexable' => true,
            'status' => PublishStatus::Published,
            'sort_order' => 0,
            'starts_at' => null,
            'ends_at' => null,
            'published_at' => Carbon::now()->subDay(),
        ];
    }

    public function draft(): self
    {
        return $this->state(fn (): array => [
            'status' => PublishStatus::Draft,
            'published_at' => null,
        ]);
    }

    public function scheduled(?Carbon $startsAt = null): self
    {
        return $this->state(fn (): array => [
            'status' => PublishStatus::Scheduled,
            'starts_at' => $startsAt ?? Carbon::now()->addDay(),
        ]);
    }

    public function expired(): self
    {
        return $this->state(fn (): array => [
            'status' => PublishStatus::Published,
            'starts_at' => Carbon::now()->subWeek(),
            'ends_at' => Carbon::now()->subDay(),
        ]);
    }

    /**
     * One of the seeded pages that may be edited but not deleted.
     *
     * `is_system` is not fillable — it is a delete guard, and a mass-assignable
     * one could be cleared by a crafted request body — so it is forced after
     * creation exactly as the seeder does.
     */
    public function system(): self
    {
        return $this->afterCreating(function (CmsPage $page): void {
            $page->forceFill(['is_system' => true])->saveQuietly();
        });
    }
}
