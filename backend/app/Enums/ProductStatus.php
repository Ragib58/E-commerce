<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Publication state of a catalog record.
 *
 * Shared by products, categories, and brands so "is this visible to shoppers?"
 * has exactly one answer in the codebase. The storefront never filters on a
 * literal string — it calls `ProductStatus::visible()`, which is what makes
 * adding a state (say, `Scheduled`) a single-file change.
 */
enum ProductStatus: string
{
    /** Work in progress. Never leaves the admin panel. */
    case Draft = 'draft';

    /** Live on the storefront. */
    case Published = 'published';

    /** Withdrawn from sale but retained for order history and reporting. */
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }

    /**
     * Colour token the admin panel uses for the status pill.
     */
    public function colour(): string
    {
        return match ($this) {
            self::Draft => 'amber',
            self::Published => 'emerald',
            self::Archived => 'slate',
        };
    }

    /**
     * Whether records in this state may be served to the public API.
     */
    public function isVisible(): bool
    {
        return $this === self::Published;
    }

    /**
     * States the storefront is allowed to return.
     *
     * @return array<int, string>
     */
    public static function visible(): array
    {
        return array_values(array_map(
            static fn (self $case): string => $case->value,
            array_filter(self::cases(), static fn (self $case): bool => $case->isVisible()),
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
