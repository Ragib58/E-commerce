<?php

declare(strict_types=1);

namespace App\Services\Reporting\Export;

use App\Enums\ReportType;
use Illuminate\Support\LazyCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export, written straight to the output stream.
 *
 * ## Nothing is buffered
 *
 * Rows arrive as a {@see LazyCollection} from a database cursor and are written
 * to `php://output` one at a time, with the buffer flushed per row. A fifty
 * thousand row export therefore costs the memory of one row, and the browser
 * starts receiving the file immediately rather than after the server has
 * assembled all of it.
 *
 * ## Formula injection
 *
 * A CSV cell beginning `=`, `+`, `-`, or `@` is executed as a formula when the
 * file is opened in Excel or Sheets. Since these reports contain
 * customer-supplied strings — a product name, someone's display name — a cell
 * reading `=HYPERLINK(...)` would run on the machine of whoever opens the
 * export. {@see escapeCell()} neutralises them; this is not theoretical, it is
 * the standard way a spreadsheet export becomes a phishing vector.
 */
final class CsvExporter
{
    /**
     * @param  LazyCollection<int, array<string, mixed>>  $rows
     */
    public function stream(ReportType $type, LazyCollection $rows, string $filename, string $currencySymbol = '$'): StreamedResponse
    {
        $columns = $type->columns();

        return new StreamedResponse(function () use ($columns, $rows, $currencySymbol): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            /*
             * A UTF-8 BOM, so Excel on Windows reads accented characters and
             * non-Latin scripts correctly. Without it Excel assumes the
             * system's legacy codepage and mangles every non-ASCII product
             * name — the single most common complaint about CSV exports.
             */
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, array_column($columns, 'label'));

            foreach ($rows as $row) {
                $line = [];

                foreach ($columns as $column) {
                    $line[] = $this->escapeCell(
                        $this->formatValue($row[$column['key']] ?? null, $column['type'], $currencySymbol),
                    );
                }

                fputcsv($handle, $line);

                // Push each row to the client rather than letting PHP's buffer
                // accumulate the whole file before anything is sent.
                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            }

            fclose($handle);
        }, 200, $this->headers($filename));
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $filename): array
    {
        return [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            // Streamed responses have no known length, and a proxy that
            // buffers one defeats the point of streaming it.
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ];
    }

    /**
     * Render a value for a spreadsheet cell.
     *
     * Money becomes a plain decimal with no currency symbol and — importantly —
     * no thousands separator. `Money::decimal()` is deliberately not used here
     * despite being the obvious choice: it groups digits for human display, and
     * `1,350.00` in a CSV cell is text a spreadsheet cannot sum, or worse, is
     * split across two columns by a comma-delimited parser.
     *
     * The symbol belongs in the column header, not in every cell.
     */
    private function formatValue(mixed $value, string $type, string $currencySymbol): string
    {
        if ($value === null) {
            return '';
        }

        return match ($type) {
            'money' => number_format(((int) $value) / 100, 2, '.', ''),
            'number' => (string) (int) $value,
            'percent' => number_format((float) $value, 2, '.', ''),
            default => (string) $value,
        };
    }

    /**
     * Neutralise a cell a spreadsheet would treat as a formula.
     *
     * Prefixing with a tab is preferred over a single quote: Excel, LibreOffice
     * and Sheets all stop parsing the cell as a formula, and the tab is not
     * displayed, whereas a leading quote is visible in some viewers.
     */
    private function escapeCell(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], strict: true)
            ? "\t".$value
            : $value;
    }
}
