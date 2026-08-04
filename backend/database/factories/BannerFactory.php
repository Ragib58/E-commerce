<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BannerPlacement;
use App\Enums\PublishStatus;
use App\Models\Banner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Banner>
 */
final class BannerFactory extends Factory
{
    protected $model = Banner::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::title($this->faker->words(3, true));

        return [
            'title' => $title,
            'subtitle' => $this->faker->sentence(6),
            // A disk-relative path, as MediaService stores them — never an
            // absolute URL, so a test exercises the same expansion the
            // application does.
            'image' => 'banners/' . Str::lower(Str::random(12)) . '.jpg',
            'mobile_image' => null,
            'alt_text' => $title,
            'link_url' => '/products',
            'link_label' => 'Shop now',
            'link_external' => false,
            'placement' => BannerPlacement::HeroSlider,
            'status' => PublishStatus::Published,
            'sort_order' => 0,
            'starts_at' => null,
            'ends_at' => null,
        ];
    }

    public function draft(): self
    {
        return $this->state(fn (): array => ['status' => PublishStatus::Draft]);
    }

    public function placement(BannerPlacement $placement): self
    {
        return $this->state(fn (): array => ['placement' => $placement]);
    }

    /**
     * Published, but not yet inside its window.
     */
    public function scheduled(?Carbon $startsAt = null): self
    {
        return $this->state(fn (): array => [
            'status' => PublishStatus::Scheduled,
            'starts_at' => $startsAt ?? Carbon::now()->addDay(),
        ]);
    }

    /**
     * Published, with a window that has already closed.
     */
    public function expired(): self
    {
        return $this->state(fn (): array => [
            'status' => PublishStatus::Published,
            'starts_at' => Carbon::now()->subWeek(),
            'ends_at' => Carbon::now()->subDay(),
        ]);
    }
}
