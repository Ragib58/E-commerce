<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The bucket size of a time series.
 *
 * ## Why the SQL format string lives here
 *
 * Grouping by day means `DATE_FORMAT(created_at, '%Y-%m-%d')` in MySQL and
 * `strftime('%Y-%m-%d', created_at)` in SQLite, which the test suite uses.
 * Putting both behind {@see sqlBucket()} means the driver difference is
 * resolved in exactly one place — a chart query that hardcoded MySQL syntax
 * would pass review and then fail every test, and one that hardcoded SQLite
 * would do the reverse.
 *
 * The format strings are constants chosen here, never interpolated from
 * caller input, so a bucket expression can never carry a value that came from
 * a request.
 */
enum ReportGranularity: string
{
    case Hour = 'hour';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    public function label(): string
    {
        return match ($this) {
            self::Hour => 'Hourly',
            self::Day => 'Daily',
            self::Week => 'Weekly',
            self::Month => 'Monthly',
        };
    }

    /**
     * A SQL expression bucketing `$column` at this granularity, for the
     * connection driver in use.
     *
     * `$column` is always a literal chosen by the calling service — never a
     * request value — and the format strings are constants, so the returned
     * fragment contains nothing user-supplied.
     */
    public function sqlBucket(string $driver, string $column = 'created_at'): string
    {
        $format = $this->format();

        return match ($driver) {
            'sqlite' => sprintf("strftime('%s', %s)", $this->sqliteFormat(), $column),
            'pgsql' => sprintf("to_char(%s, '%s')", $column, $this->postgresFormat()),
            default => sprintf("DATE_FORMAT(%s, '%s')", $column, $format),
        };
    }

    /**
     * The MySQL `DATE_FORMAT` mask.
     *
     * Week buckets use `%x-W%v` — ISO year with ISO week — rather than
     * `%Y-W%u`. In the first days of January the calendar year and the ISO
     * week-numbering year disagree, and mixing them produces a bucket like
     * "2025-W52" for a date in 2026 that then sorts before every other bucket
     * in the series.
     */
    private function format(): string
    {
        return match ($this) {
            self::Hour => '%Y-%m-%d %H:00',
            self::Day => '%Y-%m-%d',
            self::Week => '%x-W%v',
            self::Month => '%Y-%m',
        };
    }

    private function sqliteFormat(): string
    {
        return match ($this) {
            self::Hour => '%Y-%m-%d %H:00',
            self::Day => '%Y-%m-%d',
            // SQLite has no ISO-week token; %W is week-of-year from Monday,
            // which is close enough for a chart axis and sorts correctly.
            self::Week => '%Y-W%W',
            self::Month => '%Y-%m',
        };
    }

    private function postgresFormat(): string
    {
        return match ($this) {
            self::Hour => 'YYYY-MM-DD HH24:00',
            self::Day => 'YYYY-MM-DD',
            self::Week => 'IYYY-"W"IW',
            self::Month => 'YYYY-MM',
        };
    }

    /**
     * The step used to walk a range when filling gaps in a series.
     *
     * A day with no orders returns no row from `GROUP BY`, and a chart that
     * simply omits it draws a line straight from the day before to the day
     * after — visually identical to a flat period rather than a zero. The
     * series builder walks the range with this step and fills the holes.
     */
    public function stepInterval(): string
    {
        return match ($this) {
            self::Hour => '1 hour',
            self::Day => '1 day',
            self::Week => '1 week',
            self::Month => '1 month',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
