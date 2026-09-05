<?php

declare(strict_types=1);

namespace App\Services\Reporting\Export;

use App\Enums\ExportFormat;
use App\Enums\ReportType;
use App\Support\DateRange;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;
use RuntimeException;

/**
 * PDF export, rendered through the same Dompdf setup invoices use.
 *
 * ## Landscape, and why the row cap is low
 *
 * Unlike CSV and Excel this cannot stream: Dompdf needs the whole document
 * before it can paginate, so the entire table is held in memory and laid out at
 * once. {@see ExportFormat::maxRows()} caps PDF far below the other
 * formats for that reason — a forty-thousand-row PDF is minutes of CPU
 * producing something nobody reads, and the API refuses it with an explanation
 * rather than attempting it.
 *
 * Reports are wide, so pages are landscape. The order report's fourteen columns
 * do not fit portrait A4 at a readable size.
 *
 * ## Remote resources stay off
 *
 * Same reasoning as InvoiceService: these tables contain customer-supplied
 * strings, and with remote loading enabled a crafted value reaching an `img`
 * src or a CSS `url()` would make the renderer issue HTTP requests from inside
 * the network — server-side request forgery via a sales report.
 */
final class PdfExporter
{
    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     * @param  array<string, int|float>|null  $totals
     *
     * @throws RuntimeException when the PDF library is unavailable.
     */
    public function build(
        ReportType $type,
        iterable $rows,
        ?DateRange $range,
        string $storeName,
        string $currencySymbol,
        ?array $totals = null,
    ): string {
        if (! $this->supported()) {
            throw new RuntimeException(
                'PDF export requires the dompdf/dompdf package. Run: composer require dompdf/dompdf',
            );
        }

        $html = View::make('documents.report', [
            'type' => $type,
            'columns' => $type->columns(),
            'rows' => $rows,
            'range' => $range,
            'storeName' => $storeName,
            'currencySymbol' => $currencySymbol,
            'totals' => $totals,
            'generatedAt' => now(),
        ])->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $output = $dompdf->output();

        if ($output === null) {
            throw new RuntimeException('The PDF could not be generated.');
        }

        return $output;
    }

    /**
     * Whether PDF rendering is available in this installation.
     *
     * Read by the controller so a missing optional package produces a clear
     * 503 rather than a class-not-found 500 from inside the renderer.
     */
    public function supported(): bool
    {
        return class_exists(Dompdf::class);
    }
}
