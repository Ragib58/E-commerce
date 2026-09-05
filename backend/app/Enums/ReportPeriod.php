<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The named date ranges the dashboard and reports filter by.
 *
 * ## Why the presets are an enum rather than two request dates
 *
 * Every endpoint in this phase accepts a range, and a preset carries three
 * things a raw pair of dates does not: a stable cache key, a sensible chart
 * granularity, and a comparison window. "Last 7 days" grouped by month is one
 * bar; grouped by hour it is 168. {@see granularity()} is what stops every
 * caller from re-deriving that, and getting it inconsistently different.
 *
 * `Custom` is the one case that does need explicit dates, and it is the only
 * one {@see requiresExplicitDates()} returns true for — so a controller can
 * demand them for exactly that case rather than validating dates it will
 * ignore for the other five.
 */
enum ReportPeriod: string
{
    case Today = 'today';
    case Last7Days = 'last_7_days';
    case Last30Days = 'last_30_days';
    case ThisMonth = 'this_month';
    case ThisYear = 'this_year';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Today => 'Today',
            self::Last7Days => 'Last 7 days',
            self::Last30Days => 'Last 30 days',
            self::ThisMonth => 'This month',
            self::ThisYear => 'This year',
            self::Custom => 'Custom range',
        };
    }

    /**
     * How a time series over this period should be bucketed.
     *
     * Chosen so a chart lands between roughly 7 and 60 points: enough to show
     * a shape, few enough to label. A year by day is 365 unreadable bars, and
     * a single day by day is one.
     */
    public function granularity(): ReportGranularity
    {
        return match ($this) {
            self::Today => ReportGranularity::Hour,
            self::Last7Days, self::Last30Days, self::ThisMonth => ReportGranularity::Day,
            self::ThisYear => ReportGranularity::Month,
            // Resolved from the actual span instead — see DateRange::forPeriod().
            self::Custom => ReportGranularity::Day,
        };
    }

    /**
     * Whether the caller must supply `from` and `to` for this period.
     */
    public function requiresExplicitDates(): bool
    {
        return $this === self::Custom;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, array{value: string, label: string, requires_dates: bool}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
                'requires_dates' => $case->requiresExplicitDates(),
            ],
            self::cases(),
        );
    }
}
