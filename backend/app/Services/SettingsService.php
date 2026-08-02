<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SettingGroup;
use App\Enums\SettingType;
use App\Events\SettingsUpdated;
use App\Models\Setting;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for reading and writing dynamic settings.
 *
 * Both the public API and the Blade admin panel call this service, so a change
 * made from either path takes the identical code path — including cache
 * invalidation. Nothing should query the Setting model directly.
 *
 * Caching: the full public payload is cached under one tagged key rather than
 * one key per setting. The storefront always requests the whole branding
 * payload, so a single key means one Redis round-trip per request instead of
 * thirty, and invalidation is a single tag flush.
 */
final class SettingsService
{
    private const CACHE_KEY_PUBLIC = 'settings:public';

    private const CACHE_KEY_ALL = 'settings:all';

    public function __construct(
        private readonly MediaService $media,
    ) {}

    /**
     * Every setting, keyed by setting key, cast to its declared PHP type.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->remember(self::CACHE_KEY_ALL, function (): array {
            return Setting::query()
                ->ordered()
                ->get()
                ->mapWithKeys(fn (Setting $setting): array => [
                    $setting->key => $setting->typedValue(),
                ])
                ->all();
        });
    }

    /**
     * Settings exposable to unauthenticated storefront clients, grouped.
     *
     * Shape: ['branding' => ['logo' => '...'], 'theme' => ['primary_color' => '#...']]
     * The frontend consumes this directly as its branding payload.
     *
     * @return array<string, array<string, mixed>>
     */
    public function publicGrouped(): array
    {
        return $this->remember(self::CACHE_KEY_PUBLIC, function (): array {
            return Setting::query()
                ->public()
                ->ordered()
                ->get()
                ->groupBy(fn (Setting $setting): string => $setting->group->value)
                ->map(fn (Collection $group): array => $group
                    ->mapWithKeys(fn (Setting $setting): array => [
                        // Strip the group prefix: `branding.logo` -> `logo`.
                        $this->shortKey($setting->key) => $setting->typedValue(),
                    ])
                    ->all())
                ->all();
        });
    }

    /**
     * A single setting value, cast to its declared type.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * Every setting row, grouped, for the admin editing surface.
     *
     * Unlike publicGrouped() this returns whole models — the panel needs the
     * label, description, type, and lock flag to render a form field, not just
     * the value. It is uncached and includes private groups, so it must only
     * ever be reached behind the `manage_settings` permission.
     *
     * @return array<string, Collection<int, Setting>>
     */
    public function allForAdmin(?SettingGroup $group = null): array
    {
        return Setting::query()
            ->when($group !== null, fn ($query) => $query->group($group))
            ->ordered()
            ->get()
            ->groupBy(fn (Setting $setting): string => $setting->group->value)
            ->all();
    }

    /**
     * The raw stored value for a key, bypassing the typed cast.
     *
     * Media settings need this: typedValue() expands a stored path into an
     * absolute URL, but replacing or deleting the file requires the path.
     */
    public function rawValue(string $key): ?string
    {
        /** @var Setting|null $setting */
        $setting = Setting::query()->where('key', $key)->first();

        return $setting?->value;
    }

    /**
     * Validation rules for a set of keys, derived from each setting's type.
     *
     * The admin form is generated from the database, so its validation must be
     * too — a hardcoded rule map would drift the moment a setting is added
     * from the panel.
     *
     * @param  array<int, string>  $keys
     * @return array<string, array<int, string>>
     */
    public function validationRulesFor(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return Setting::query()
            ->whereIn('key', $keys)
            ->get()
            ->mapWithKeys(fn (Setting $setting): array => [
                $setting->key => $setting->type->validationRules(),
            ])
            ->all();
    }

    /**
     * Store an uploaded brand asset against a setting, replacing any previous
     * file and invalidating the caches.
     *
     * Returns the absolute URL so the caller can echo it straight back to the
     * admin UI for an immediate preview.
     */
    public function setMedia(string $key, UploadedFile $file, string $directory = 'branding'): string
    {
        $path = $this->media->replace($file, $this->rawValue($key), $directory);

        $this->set($key, $path, SettingType::Image);

        return (string) $this->media->url($path);
    }

    /**
     * Clear a media setting and delete the underlying file.
     *
     * The row is kept with a null value rather than deleted: the key is part of
     * the seeded schema and the admin form still needs to render its field.
     */
    public function clearMedia(string $key): void
    {
        $path = $this->rawValue($key);

        $this->set($key, null, SettingType::Image);

        $this->media->delete($path);
    }

    /**
     * All settings in one group, keyed by short key.
     *
     * @return array<string, mixed>
     */
    public function group(SettingGroup|string $group): array
    {
        $value = $group instanceof SettingGroup ? $group->value : $group;

        return $this->publicGrouped()[$value] ?? [];
    }

    /**
     * Create or update a single setting and invalidate the caches.
     *
     * The value is serialised through the setting's type, so callers pass
     * native PHP values (bool, array) and never worry about storage encoding.
     */
    public function set(
        string $key,
        mixed $value,
        ?SettingType $type = null,
        ?SettingGroup $group = null,
    ): Setting {
        $setting = DB::transaction(function () use ($key, $value, $type, $group): Setting {
            /** @var Setting|null $existing */
            $existing = Setting::query()->where('key', $key)->lockForUpdate()->first();

            $resolvedType = $type ?? $existing?->type ?? SettingType::String;
            $resolvedGroup = $group ?? $existing?->group ?? SettingGroup::General;

            if ($existing !== null) {
                $existing->fill([
                    'value' => $resolvedType->serialize($value),
                    'type' => $resolvedType,
                    'group' => $resolvedGroup,
                ])->save();

                return $existing;
            }

            return Setting::query()->create([
                'key' => $key,
                'value' => $resolvedType->serialize($value),
                'type' => $resolvedType,
                'group' => $resolvedGroup,
                'is_public' => $resolvedGroup->isPubliclyExposable(),
            ]);
        });

        $this->flush([$key]);

        return $setting;
    }

    /**
     * Write many settings in one transaction, flushing the cache once.
     *
     * This is what the admin panel's "save settings form" calls — a per-key
     * loop over set() would flush the cache N times and emit N events.
     *
     * @param  array<string, mixed>  $values  Keyed by setting key.
     */
    public function setMany(array $values): void
    {
        if ($values === []) {
            return;
        }

        DB::transaction(function () use ($values): void {
            /** @var Collection<string, Setting> $existing */
            $existing = Setting::query()
                ->whereIn('key', array_keys($values))
                ->lockForUpdate()
                ->get()
                ->keyBy('key');

            foreach ($values as $key => $value) {
                $setting = $existing->get($key);

                if ($setting === null) {
                    $group = $this->groupFromKey($key);

                    Setting::query()->create([
                        'key' => $key,
                        'value' => SettingType::String->serialize($value),
                        'type' => SettingType::String,
                        'group' => $group,
                        'is_public' => $group->isPubliclyExposable(),
                    ]);

                    continue;
                }

                $setting->value = $setting->type->serialize($value);
                $setting->save();
            }
        });

        $this->flush(array_keys($values));
    }

    /**
     * Drop a setting. Locked settings are infrastructure keys and are refused.
     */
    public function forget(string $key): bool
    {
        /** @var Setting|null $setting */
        $setting = Setting::query()->where('key', $key)->first();

        if ($setting === null || $setting->is_locked) {
            return false;
        }

        $setting->delete();
        $this->flush([$key]);

        return true;
    }

    /**
     * Invalidate every cached settings payload and announce the change.
     *
     * The event drives downstream invalidation (the Next.js ISR cache) via a
     * listener, keeping this service free of HTTP concerns.
     *
     * @param  array<int, string>  $changedKeys
     */
    public function flush(array $changedKeys = []): void
    {
        $this->cache()->flush();

        SettingsUpdated::dispatch($changedKeys);
    }

    /**
     * Monotonic version stamp for the current settings state.
     *
     * The frontend sends this back as a cache key component, so a settings
     * change produces a new key and cannot serve a stale branding payload.
     */
    public function version(): string
    {
        return (string) Cache::get('settings:version', '1');
    }

    public function bumpVersion(): void
    {
        // Not tagged: the version must survive the tag flush that accompanies
        // every write, otherwise it would reset to 1 on each change.
        Cache::forever('settings:version', (string) (((int) $this->version()) + 1));
    }

    /**
     * @template TValue
     *
     * @param  \Closure(): TValue  $callback
     * @return TValue
     */
    private function remember(string $key, \Closure $callback): mixed
    {
        return $this->cache()->remember(
            $key,
            (int) config('cache.ttl.settings', 86400),
            $callback
        );
    }

    private function cache(): Repository
    {
        $store = Cache::store();

        // Tags require Redis/Memcached. On a driver without tag support
        // (array in unit tests, file in a minimal CI box) fall back to the
        // untagged store so reads still work — flush() then clears globally.
        if (! $this->supportsTags()) {
            return $store;
        }

        return Cache::tags([(string) config('cache.tags.settings', 'settings')]);
    }

    private function supportsTags(): bool
    {
        return in_array(
            config('cache.default'),
            ['redis', 'memcached', 'dynamodb', 'array'],
            strict: true
        );
    }

    /**
     * `branding.logo` -> `logo`. Keys without a prefix are returned as-is.
     */
    private function shortKey(string $key): string
    {
        $position = strpos($key, '.');

        return $position === false ? $key : substr($key, $position + 1);
    }

    private function groupFromKey(string $key): SettingGroup
    {
        $prefix = strtok($key, '.');

        return SettingGroup::tryFrom((string) $prefix) ?? SettingGroup::General;
    }
}
