<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Publication state for editorial content — CMS pages and banners.
 *
 * Deliberately separate from ProductStatus despite sharing two of its names.
 * Merging them would couple the editorial lifecycle to the catalog one, and
 * they diverge: a product can be `archived` (withdrawn from sale but retained
 * for order history), which means nothing for a privacy policy, while a page
 * can be `scheduled` — published, but not yet visible — which means nothing for
 * a product whose visibility is a single flag.
 *
 * Note that `Scheduled` is a stored intent, not a computed state: whether a
 * scheduled page is *currently* visible depends on its window, and that
 * question is answered by the model's `visible` scope in SQL rather than by
 * comparing dates in PHP after the rows are already loaded.
 */
enum PublishStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Scheduled = 'scheduled';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Scheduled => 'Scheduled',
            self::Archived => 'Archived',
        };
    }

    /**
     * Whether this status permits public display at all.
     *
     * A `true` here is necessary but not sufficient — the scheduling window is
     * checked separately, because a scheduled item is only visible inside it.
     */
    public function isPublishable(): bool
    {
        return in_array($this, [self::Published, self::Scheduled], strict: true);
    }

    /**
     * The statuses a public query may consider.
     *
     * @return array<int, string>
     */
    public static function publishable(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isPublishable()),
        ));
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
