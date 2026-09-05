<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\ExportFormat;
use App\Enums\ReportType;
use App\Services\Reporting\Export\CsvExporter;
use App\Services\Reporting\Export\ExcelExporter;
use App\Services\Reporting\Export\PdfExporter;
use App\Services\SettingsService;
use App\Support\DateRange;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Turns a report into a downloadable file.
 *
 * ## One place decides what an export is allowed to be
 *
 * The row cap, the filename, the content-disposition header, and the check
 * that the requested format is actually available all live here rather than in
 * the controller. A controller that assembled its own headers would eventually
 * pair an `.xlsx` filename with a CSV body, and the row cap enforced at one
 * call site is a row cap that a second export endpoint forgets.
 *
 * ## Failing before the work, not during it
 *
 * {@see assertExportable()} runs before a single row is fetched. An export
 * that is going to be refused for being too large should be refused
 * immediately, with a message that says how to narrow it — not after two
 * minutes of streaming, by which point the client has already received a
 * partial file with a 200 status that cannot be retracted.
 */
final class ExportService
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly CsvExporter $csv,
        private readonly ExcelExporter $excel,
        private readonly PdfExporter $pdf,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Build the download response for a report.
     *
     * @param  array<string, mixed>  $filters
     *
     * @throws ValidationException when the format is unavailable or the result
     *                             set exceeds what the format can carry.
     */
    public function export(
        ReportType $type,
        ExportFormat $format,
        DateRange $range,
        array $filters = [],
    ): SymfonyResponse {
        $this->assertFormatAvailable($format);

        $rowCount = $this->reports->rowCount($type, $range, $filters);
        $this->assertWithinRowLimit($format, $rowCount);

        $filename = $this->filename($type, $format, $range);
        $symbol = $this->currencySymbol();

        return match ($format) {
            ExportFormat::Csv => $this->csv->stream(
                $type,
                $this->reports->cursor($type, $range, $filters),
                $filename,
                $symbol,
            ),

            ExportFormat::Excel => $this->download(
                $this->excel->build(
                    $type,
                    $this->reports->cursor($type, $range, $filters),
                    $type->label(),
                ),
                $filename,
                $format,
            ),

            ExportFormat::Pdf => $this->download(
                $this->pdf->build(
                    type: $type,
                    /*
                     * Materialised rather than streamed: Dompdf needs the whole
                     * table before it can paginate, and the Blade template
                     * iterates the rows twice — once for the body, and the
                     * layout engine again when it measures. A LazyCollection
                     * would be exhausted after the first pass and render an
                     * empty table.
                     */
                    rows: $this->reports->cursor($type, $range, $filters)->all(),
                    range: $type->isDateScoped() ? $range : null,
                    storeName: $this->storeName(),
                    currencySymbol: $symbol,
                    totals: null,
                ),
                $filename,
                $format,
            ),
        };
    }

    /**
     * The formats this installation can actually produce.
     *
     * Both spreadsheet and PDF output depend on optional extensions. Reporting
     * which are usable lets the panel hide a button rather than offering one
     * that returns a 503.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableFormats(): array
    {
        return array_values(array_filter(
            ExportFormat::options(),
            fn (array $option): bool => $this->isFormatAvailable(ExportFormat::from($option['value'])),
        ));
    }

    private function isFormatAvailable(ExportFormat $format): bool
    {
        return match ($format) {
            ExportFormat::Csv => true,
            ExportFormat::Excel => $this->excel->supported(),
            ExportFormat::Pdf => $this->pdf->supported(),
        };
    }

    /**
     * @throws ValidationException
     */
    private function assertFormatAvailable(ExportFormat $format): void
    {
        if ($this->isFormatAvailable($format)) {
            return;
        }

        throw ValidationException::withMessages([
            'format' => [sprintf(
                '%s export is not available on this installation.',
                $format->label(),
            )],
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function assertWithinRowLimit(ExportFormat $format, int $rowCount): void
    {
        $max = $format->maxRows();

        if ($rowCount <= $max) {
            return;
        }

        throw ValidationException::withMessages([
            'format' => [sprintf(
                'This report has %s rows, more than the %s a %s export can carry. Narrow the date range or add a filter.',
                number_format($rowCount),
                number_format($max),
                $format->label(),
            )],
        ]);
    }

    /**
     * A descriptive, filesystem-safe filename.
     *
     * Carries the range so a folder of exports is self-describing — an archive
     * of files all called `sales-report.csv` is one nobody can navigate.
     */
    private function filename(ReportType $type, ExportFormat $format, DateRange $range): string
    {
        $stem = $type->filenameStem();

        $window = $type->isDateScoped()
            ? sprintf('%s_%s', $range->from->format('Y-m-d'), $range->to->format('Y-m-d'))
            : Carbon::now()->format('Y-m-d');

        return sprintf('%s_%s.%s', $stem, $window, $format->extension());
    }

    private function download(string $contents, string $filename, ExportFormat $format): Response
    {
        return new Response($contents, 200, [
            'Content-Type' => $format->mimeType(),
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Content-Length' => (string) strlen($contents),
            // A report is a snapshot of live figures; a cached copy served
            // later would be presented as current.
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    private function storeName(): string
    {
        return (string) $this->settings->get('business.store_name', config('app.name', 'Store'));
    }

    private function currencySymbol(): string
    {
        return (string) $this->settings->get('business.currency_symbol', '$');
    }
}
