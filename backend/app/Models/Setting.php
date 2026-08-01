<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SettingGroup;
use App\Enums\SettingType;
use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A single admin-managed configuration value.
 *
 * Settings use an entity-attribute-value shape deliberately: adding a new
 * brandable field (a new social link, a new theme colour) is an INSERT from the
 * admin panel, not a migration and a deploy. The `type` column restores type
 * fidelity on read, and `is_public` gates exposure through the public API.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property SettingType $type
 * @property SettingGroup $group
 * @property string|null $label
 * @property string|null $description
 * @property bool $is_public
 * @property bool $is_locked
 * @property int $sort_order
 */
#[UseFactory(SettingFactory::class)]
class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'label',
        'description',
        'is_public',
        'is_locked',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SettingType::class,
            'group' => SettingGroup::class,
            'is_public' => 'boolean',
            'is_locked' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The value cast to its declared PHP type.
     *
     * Accessing `$setting->value` returns the raw string from the database;
     * this returns a real bool/int/array. File-typed settings are expanded to
     * absolute URLs so the frontend can use them directly in an <img src>.
     */
    public function typedValue(): mixed
    {
        if ($this->type->isFileReference()) {
            return $this->resolveFileUrl($this->value);
        }

        return $this->type->cast($this->value);
    }

    /**
     * Expand a stored disk path into a publicly reachable absolute URL.
     *
     * Values that are already absolute URLs (an externally hosted CDN asset)
     * are returned untouched.
     */
    private function resolveFileUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $disk = Storage::disk(config('filesystems.default'));

        return $disk->url($path);
    }

    /**
     * Settings safe to expose to unauthenticated storefront clients.
     *
     * Requires both the per-row flag and a publicly-exposable group, so a
     * mistaken `is_public` toggle on a payment credential still cannot leak.
     *
     * @param  Builder<Setting>  $query
     * @return Builder<Setting>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query
            ->where('is_public', true)
            ->whereIn('group', array_map(
                static fn (SettingGroup $group): string => $group->value,
                SettingGroup::publiclyExposable()
            ));
    }

    /**
     * @param  Builder<Setting>  $query
     * @return Builder<Setting>
     */
    public function scopeGroup(Builder $query, SettingGroup|string $group): Builder
    {
        return $query->where('group', $group instanceof SettingGroup ? $group->value : $group);
    }

    /**
     * @param  Builder<Setting>  $query
     * @return Builder<Setting>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('group')->orderBy('sort_order')->orderBy('key');
    }
}
