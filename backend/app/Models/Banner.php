<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BannerPlacement;
use App\Enums\PublishStatus;
use App\Services\MediaService;
use App\Traits\Schedulable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute as CastAttribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A promotional image bound to a storefront placement.
 *
 * The storefront asks for banners *by placement*, never by id, so retargeting a
 * campaign is a field edit rather than a frontend change.
 *
 * @property int $id
 * @property string $title
 * @property string|null $subtitle
 * @property string $image
 * @property string|null $mobile_image
 * @property string|null $alt_text
 * @property string|null $link_url
 * @property string|null $link_label
 * @property bool $link_external
 * @property BannerPlacement $placement
 * @property PublishStatus $status
 * @property int $sort_order
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property-read string|null $image_url
 * @property-read string|null $mobile_image_url
 */
class Banner extends Model
{
    /** @use HasFactory<\Database\Factories\BannerFactory> */
    use HasFactory;
    use Schedulable;
    use SoftDeletes;

    protected $fillable = [
        'title',
        'subtitle',
        'image',
        'mobile_image',
        'alt_text',
        'link_url',
        'link_label',
        'link_external',
        'placement',
        'status',
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
            'placement' => BannerPlacement::class,
            'status' => PublishStatus::class,
            'link_external' => 'boolean',
            'sort_order' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Banners a visitor may actually see right now.
     *
     * Publishable status AND an open window — both, because either alone is a
     * bug: status without the window shows a campaign before it launches, and
     * the window without status shows a draft.
     *
     * @param  Builder<Banner>  $query
     * @return Builder<Banner>
     */
    public function scopeLive(Builder $query, ?Carbon $at = null): Builder
    {
        return $query
            ->whereIn('status', PublishStatus::publishable())
            ->withinWindow($at);
    }

    /**
     * @param  Builder<Banner>  $query
     * @return Builder<Banner>
     */
    public function scopePlacement(Builder $query, BannerPlacement|string $placement): Builder
    {
        return $query->where(
            'placement',
            $placement instanceof BannerPlacement ? $placement->value : $placement,
        );
    }

    /**
     * @param  Builder<Banner>  $query
     * @return Builder<Banner>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderByDesc('id');
    }

    /**
     * @return CastAttribute<string|null, never>
     */
    protected function imageUrl(): CastAttribute
    {
        return CastAttribute::make(
            get: fn (): ?string => app(MediaService::class)->url($this->image),
        )->shouldCache();
    }

    /**
     * @return CastAttribute<string|null, never>
     */
    protected function mobileImageUrl(): CastAttribute
    {
        return CastAttribute::make(
            // Falls back to the desktop image rather than returning null: a
            // banner without art direction must still render on a phone.
            get: fn (): ?string => app(MediaService::class)->url($this->mobile_image ?? $this->image),
        )->shouldCache();
    }

    /**
     * Alt text, never empty.
     *
     * An image with a missing alt attribute is invisible to a screen reader,
     * so the title stands in when an operator leaves the field blank.
     */
    protected function resolvedAltText(): CastAttribute
    {
        return CastAttribute::make(
            get: fn (): string => $this->alt_text ?: $this->title,
        )->shouldCache();
    }
}
