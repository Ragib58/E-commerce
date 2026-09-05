<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The file formats a report can be downloaded as.
 *
 * Each case carries its own MIME type and extension so a controller never has
 * to `match` on the format a second time to build the response headers — the
 * pairing of "xlsx" with the wrong content type is exactly the kind of thing
 * that works in one browser and silently downloads a corrupt file in another.
 */
enum ExportFormat: string
{
    case Csv = 'csv';
    case Excel = 'xlsx';
    case Pdf = 'pdf';

    public function label(): string
    {
        return match ($this) {
            self::Csv => 'CSV',
            self::Excel => 'Excel',
            self::Pdf => 'PDF',
        };
    }

    public function extension(): string
    {
        return $this->value;
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv',
            self::Excel => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Pdf => 'application/pdf',
        };
    }

    /**
     * Whether this format is streamed row by row rather than built in memory.
     *
     * CSV and Excel are written incrementally, so a fifty-thousand-row export
     * holds one row at a time. A PDF cannot be: its layout engine needs the
     * whole document before it can paginate, which is why {@see maxRows()}
     * caps it far lower.
     */
    public function isStreamed(): bool
    {
        return $this !== self::Pdf;
    }

    /**
     * The row ceiling for this format.
     *
     * PDF is deliberately restrictive. A print-oriented document of forty
     * thousand rows is not something anyone reads — it is a spreadsheet that
     * has been made harder to use — and rendering one costs minutes of CPU and
     * gigabytes of memory. The API says so rather than attempting it.
     */
    public function maxRows(): int
    {
        $configured = (int) config('reporting.limits.max_export_rows', 50000);

        return $this === self::Pdf
            ? min($configured, 5000)
            : $configured;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, array{value: string, label: string, max_rows: int}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
                'max_rows' => $case->maxRows(),
            ],
            self::cases(),
        );
    }
}
