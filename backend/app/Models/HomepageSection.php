<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SectionType;
use App\Traits\Schedulable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One block of the homepage.
 *
 * The homepage is the ordered list of these rows — there is no template
 * enumerating sections, so the page's structure is data an operator edits.
 *
 * `settings` holds the type-specific payload. It is merged over the type's
 * defaults on read, which is what stops a section added before a new setting
 * existed from rendering with that setting undefined.
 *
 * @property int $id
 * @property SectionType $type
 * @property string $name
 * @property string|null $heading
 * @property string|null $subheading
 * @property array<string, mixed>|null $settings
 * @property string|null $background_color
 * @property string|null $container_width
 * @property bool $is_enabled
 * @property int $sort_order
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 */
class HomepageSection extends Model
{
    /** @use HasFactory<\Database\Factories\HomepageSectionFactory> */
    use HasFactory;
    use Schedulable;
    use SoftDeletes;

    protected $fillable = [
        'type',
        'name',
        'heading',
        'subheading',
        'settings',
        'background_color',
        'container_width',
        'is_enabled',
        'sort_order',
        'starts_at',
        'ends_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SectionType::class,
            'settings' => 'array',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Sections the storefront should render right now.
     *
     * @param  Builder<HomepageSection>  $query
     * @return Builder<HomepageSection>
     */
    public function scopeLive(Builder $query, ?Carbon $at = null): Builder
    {
        return $query->where('is_enabled', true)->withinWindow($at);
    }

    /**
     * @param  Builder<HomepageSection>  $query
     * @return Builder<HomepageSection>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        // id breaks ties deterministically. Without it, two sections sharing a
        // sort_order can swap places between requests, which looks like the
        // page reordering itself at random.
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Settings with the type's defaults filled in.
     *
     * Always read through this rather than `$section->settings` directly: a
     * section saved before a setting was introduced has no key for it, and the
     * renderer would otherwise branch on null and silently drop a feature.
     *
     * @return array<string, mixed>
     */
    public function resolvedSettings(): array
    {
        return array_merge($this->type->defaultSettings(), $this->settings ?? []);
    }

    /**
     * One setting, defaulted.
     */
    public function setting(string $key, mixed $fallback = null): mixed
    {
        return $this->resolvedSettings()[$key] ?? $fallback;
    }

    /**
     * A bounded integer setting.
     *
     * Clamped because these values reach a SQL LIMIT: a section configured
     * with a limit of 100000 would otherwise let one bad save turn the
     * homepage into a full catalog dump.
     */
    public function intSetting(string $key, int $fallback, int $min = 1, int $max = 48): int
    {
        $value = $this->setting($key, $fallback);

        if (! is_numeric($value)) {
            return $fallback;
        }

        return max($min, min($max, (int) $value));
    }

    /**
     * An array setting, with non-integer members discarded.
     *
     * Used for the id lists (`product_ids`, `category_ids`) that a hand-picked
     * collection stores. JSON is not a foreign key, so nothing guarantees the
     * contents are integers, and passing raw values into whereIn would be an
     * injection surface on a driver without prepared statements.
     *
     * @return array<int, int>
     */
    public function idListSetting(string $key, int $max = 48): array
    {
        $value = $this->setting($key, []);

        if (! is_array($value)) {
            return [];
        }

        return array_slice(
            array_values(array_unique(array_map(
                static fn (mixed $id): int => (int) $id,
                array_filter($value, static fn (mixed $id): bool => is_numeric($id)),
            ))),
            0,
            $max,
        );
    }
}
