<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SectionType;
use App\Models\HomepageSection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<HomepageSection>
 */
final class HomepageSectionFactory extends Factory
{
    protected $model = HomepageSection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = SectionType::FeaturedProducts;

        return [
            'type' => $type,
            'name' => $type->label(),
            'heading' => $type->label(),
            'subheading' => null,
            // The type's own defaults, so a factory-made section is complete in
            // the same way a panel-made one is.
            'settings' => $type->defaultSettings(),
            'background_color' => null,
            'container_width' => null,
            'is_enabled' => true,
            'sort_order' => 0,
            'starts_at' => null,
            'ends_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function ofType(SectionType $type, array $settings = []): self
    {
        return $this->state(fn (): array => [
            'type' => $type,
            'name' => $type->label(),
            'settings' => array_merge($type->defaultSettings(), $settings),
        ]);
    }

    public function disabled(): self
    {
        return $this->state(fn (): array => ['is_enabled' => false]);
    }

    /**
     * Enabled, but its window has not opened.
     */
    public function scheduled(?Carbon $startsAt = null): self
    {
        return $this->state(fn (): array => [
            'is_enabled' => true,
            'starts_at' => $startsAt ?? Carbon::now()->addDay(),
        ]);
    }

    /**
     * Enabled, with a window that has already closed.
     */
    public function expired(): self
    {
        return $this->state(fn (): array => [
            'is_enabled' => true,
            'starts_at' => Carbon::now()->subWeek(),
            'ends_at' => Carbon::now()->subDay(),
        ]);
    }
}
